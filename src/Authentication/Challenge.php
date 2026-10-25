<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Authentication;
use PhpMinecraftJavaServer\Protocol\{Binary,ProtocolException};

/** RSA handshake as used by Crypt and ServerLoginPacketListenerImpl in the pinned server. */
final class Challenge
{
    private readonly \OpenSSLAsymmetricKey $private;
    public readonly string $public;
    public function __construct(?\OpenSSLAsymmetricKey $key=null) {
        $generated=$key??openssl_pkey_new(['private_key_bits'=>1024,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
        if($generated===false){throw new \RuntimeException('Cannot create Minecraft RSA key');}
        $this->private=$generated;
        $details=openssl_pkey_get_details($this->private);
        if($details===false || $details['type']!==OPENSSL_KEYTYPE_RSA || $details['bits']!==1024){throw new \RuntimeException('Cannot create Minecraft RSA key');}
        $this->public=base64_decode(preg_replace('/-----[^-]+-----|\s/','',$details['key']),true);
    }
    public function packet(string $nonce):string {
        if(strlen($nonce)!==4){throw new \InvalidArgumentException('Expected a four-byte login challenge');}
        return Binary::writeString('').Binary::writeVarInt(strlen($this->public)).$this->public.Binary::writeVarInt(4).$nonce."\1";
    }
    public function secret(Binary $reader,string $nonce):string {
        $encrypted=$this->field($reader);$token=$this->field($reader);$reader->end();
        $validToken=openssl_private_decrypt($token,$actual,$this->private,OPENSSL_PKCS1_PADDING);
        $validSecret=openssl_private_decrypt($encrypted,$secret,$this->private,OPENSSL_PKCS1_PADDING);
        if(!$validToken || !$validSecret || strlen($nonce)!==4 || !hash_equals($nonce,$actual) || strlen($secret)!==16){throw new ProtocolException('Invalid login challenge response');}
        return $secret;
    }
    private function field(Binary $reader):string {
        if($reader->varInt()!==128){throw new ProtocolException('Invalid RSA response length');}
        return $reader->bytes(128);
    }
    public function hash(string $secret):string {return self::signedHex(sha1($secret.$this->public,true));}
    public static function signedHex(string $digest):string {
        if(strlen($digest)!==20){throw new \InvalidArgumentException('Expected a SHA-1 digest');}
        $negative=(ord($digest[0])&128)!==0;
        if($negative){$carry=1;for($i=19;$i>=0;--$i){$n=(ord($digest[$i])^255)+$carry;$digest[$i]=chr($n&255);$carry=$n>>8;}}
        $hex=ltrim(bin2hex($digest),'0');return ($negative?'-':'').($hex===''?'0':$hex);
    }
}
