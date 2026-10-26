<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World\Generation;

/** Java integer arithmetic with explicit wrapping; never promote overflowed products to PHP floats. */
final class Int64
{
    public static function add(int $a,int $b):int {
        $low=($a&0xffffffff)+($b&0xffffffff);$high=(($a>>32)&0xffffffff)+(($b>>32)&0xffffffff)+($low>>32);
        return ($high<<32)|($low&0xffffffff);
    }
    public static function multiply(int $a,int $b):int {
        $a0=$a&65535;$a1=($a>>16)&65535;$a2=($a>>32)&65535;$a3=($a>>48)&65535;
        $b0=$b&65535;$b1=($b>>16)&65535;$b2=($b>>32)&65535;$b3=($b>>48)&65535;
        $p=$a0*$b0;$result=$p&65535;
        $p=$a1*$b0+$a0*$b1+($p>>16);$result|=($p&65535)<<16;
        $p=$a2*$b0+$a1*$b1+$a0*$b2+($p>>16);$result|=($p&65535)<<32;
        $p=$a3*$b0+$a2*$b1+$a1*$b2+$a0*$b3+($p>>16);
        return $result|(($p&65535)<<48);
    }
    public static function unsignedShift(int $value,int $bits):int {
        $bits&=63;return $bits===0?$value:($value>>$bits)&(PHP_INT_MAX>>($bits-1));
    }
    public static function rotateLeft(int $value,int $bits):int {
        $bits&=63;return $bits===0?$value:($value<<$bits)|self::unsignedShift($value,64-$bits);
    }
    public static function signed32(int $value):int {$value&=0xffffffff;return $value>=2147483648?$value-4294967296:$value;}
    public static function coordinateSeed(int $x,int $y,int $z):int {
        $s=self::signed32($x*3129871)^self::multiply($z,116129781)^$y;
        return self::add(self::multiply(self::multiply($s,$s),42317861),self::multiply($s,11))>>16;
    }
    public static function stringHash(string $text):int {
        if(preg_match('//u',$text)!==1){throw new \InvalidArgumentException('Invalid UTF-8 seed name');}
        $utf16=iconv('UTF-8','UTF-16BE',$text);if($utf16===false){throw new \InvalidArgumentException('Invalid UTF-8 seed name');}
        $h=0;foreach(unpack('n*',$utf16) as $unit){$h=self::signed32($h*31+$unit);}return $h;
    }
    public static function mixStafford13(int $seed):int {
        $seed=self::multiply($seed^self::unsignedShift($seed,30),-4658895280553007687);
        $seed=self::multiply($seed^self::unsignedShift($seed,27),-7723592293110705685);
        return $seed^self::unsignedShift($seed,31);
    }
}
