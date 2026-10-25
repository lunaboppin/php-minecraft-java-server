<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Authentication;

/** A continuous AES/CFB8 stream. Use a separate instance for each direction. */
final class Cfb8
{
    private string $iv;
    public function __construct(private readonly string $secret,private readonly bool $decrypt=false) {
        if(strlen($secret)!==16){throw new \InvalidArgumentException('Expected a 128-bit Minecraft shared secret');}
        $this->iv=$secret;
    }
    public function transform(string $bytes):string {
        if($bytes===''){return '';}
        $result=$this->decrypt?openssl_decrypt($bytes,'aes-128-cfb8',$this->secret,OPENSSL_RAW_DATA,$this->iv):openssl_encrypt($bytes,'aes-128-cfb8',$this->secret,OPENSSL_RAW_DATA,$this->iv);
        if($result===false){throw new \RuntimeException('Minecraft stream cipher failed');}
        // CFB8 feeds ciphertext back one byte at a time, across packet/read/write boundaries.
        $this->iv=substr($this->iv.($this->decrypt?$bytes:$result),-16);
        return $result;
    }
}
