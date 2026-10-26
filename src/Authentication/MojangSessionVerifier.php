<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Authentication;

/** Bounded asynchronous HTTPS discovery/verification. There is no configurable identity endpoint. */
final class MojangSessionVerifier implements SessionVerifier
{
    private readonly \CurlMultiHandle $multi;
    private array $requests=[];
    private array $waiting=[];
    private ?string $endpoint=null;
    private float $expires=0;
    private const UNAVAILABLE='Authentication servers are unavailable. Please try again later.';
    public function __construct() {
        if(!extension_loaded('curl') || !(curl_version()['features']&CURL_VERSION_ASYNCHDNS)){throw new \RuntimeException('Online mode requires cURL with asynchronous DNS');}
        $this->multi=curl_multi_init();
    }
    public function start(int $connection,string $username,string $hash):void {
        $this->cancel($connection);
        if($this->endpoint===null || microtime(true)>$this->expires){
            $this->waiting[$connection]=[$username,$hash];
            if(!isset($this->requests[-1])){$this->request(-1,Discovery::URL);}
        }else{$this->verify($connection,$username,$hash);}
    }
    private function verify(int $connection,string $username,string $hash):void {
        $this->request($connection,$this->endpoint.'?'.http_build_query(['username'=>$username,'serverId'=>$hash]),$username);
    }
    private function request(int $connection,string $url,string $username=''):void {
        $handle=curl_init($url);$this->requests[$connection]=['handle'=>$handle,'username'=>$username,'body'=>''];
        curl_setopt_array($handle,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS_STR=>'https',CURLOPT_CONNECTTIMEOUT_MS=>3000,CURLOPT_TIMEOUT_MS=>10000,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'PHP-Minecraft-Java-Server/26.3',
            CURLOPT_WRITEFUNCTION=>function($unused,string $bytes)use($connection):int{
                if(!isset($this->requests[$connection]) || strlen($this->requests[$connection]['body'])+strlen($bytes)>65536){return 0;}
                $this->requests[$connection]['body'].=$bytes;return strlen($bytes);
            }]);curl_multi_add_handle($this->multi,$handle);
    }
    public function poll():array {
        do{$status=curl_multi_exec($this->multi,$running);}while($status===CURLM_CALL_MULTI_PERFORM);
        $results=[];
        if($status!==CURLM_OK){foreach(array_unique([...array_keys($this->requests),...array_keys($this->waiting)]) as $id){if($id>=0){$results[$id]=['error'=>self::UNAVAILABLE];}$this->cancel($id);}return $results;}
        while($message=curl_multi_info_read($this->multi)){
            foreach($this->requests as $id=>$request){
                if($request['handle']!==$message['handle']){continue;}
                $code=curl_getinfo($request['handle'],CURLINFO_RESPONSE_CODE);$ok=$message['result']===CURLE_OK;$this->cancel($id);
                if($id===-1){
                    try{
                        if(!$ok || $code!==200){throw new \InvalidArgumentException('Discovery unavailable');}
                        $this->endpoint=Discovery::sessionEndpoint($request['body']);$this->expires=microtime(true)+86400;
                        foreach($this->waiting as $waiting=>[$username,$hash]){$this->verify($waiting,$username,$hash);}
                    }catch(\JsonException|\InvalidArgumentException){foreach($this->waiting as $waiting=>$_){$results[$waiting]=['error'=>self::UNAVAILABLE];}}
                    $this->waiting=[];
                }elseif(!$ok || ($code!==200 && $code!==204)){$results[$id]=['error'=>self::UNAVAILABLE];}
                elseif($code===204){$results[$id]=['error'=>'Failed to verify username!'];}
                else{
                    try{$results[$id]=['profile'=>Profile::parse($request['body'],$request['username'])];}
                    catch(\JsonException|\InvalidArgumentException){$results[$id]=['error'=>'Failed to verify username!'];}
                }
                break;
            }
        }
        return $results;
    }
    public function cancel(int $connection):void {
        unset($this->waiting[$connection]);
        if(isset($this->requests[$connection])){curl_multi_remove_handle($this->multi,$this->requests[$connection]['handle']);unset($this->requests[$connection]);}
    }
    public function __destruct(){foreach(array_keys($this->requests) as $id){$this->cancel($id);}}
}
