<?php
// Isolated test launcher. The production entrypoint has no fixture verifier setting.
declare(strict_types=1);
require __DIR__.'/../../src/bootstrap.php';
use PhpMinecraftJavaServer\Authentication\{SessionVerifier,Profile};
use PhpMinecraftJavaServer\Server\{Server,Config};
final class FixtureSessions implements SessionVerifier {
    private array $pending=[];
    public function __construct(private readonly string $mode){}
    public function start(int $connection,string $username,string $hash):void{$this->pending[$connection]=[$username,microtime(true)+0.2];}
    public function cancel(int $connection):void{unset($this->pending[$connection]);}
    public function poll():array {
        $out=[];foreach($this->pending as $id=>[$name,$at]){if(microtime(true)<$at){continue;}
            $out[$id]=$this->mode==='verified'?['profile'=>Profile::parse(json_encode(['id'=>'112233445566778899aabbccddeeff00','name'=>$name,'properties'=>[['name'=>'textures','value'=>'fixture-value','signature'=>'fixture-signature']]]),$name)]:['error'=>'Authentication servers are unavailable. Please try again later.'];unset($this->pending[$id]);
        }return $out;
    }
}
(new Server(Config::load($argv[1]),sessions:new FixtureSessions($argv[2])))->run();
