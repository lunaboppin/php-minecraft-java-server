<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World\Generation;
final class PositionalRandom
{
    public function __construct(private readonly bool $xoroshiro,private readonly int $low,private readonly int $high=0){}
    public function at(int $x,int $y,int $z):RandomSource {
        foreach([$x,$y,$z] as $n){if($n<-2147483648 || $n>2147483647){throw new \InvalidArgumentException('Coordinate must be a Java int');}}
        $seed=Int64::coordinateSeed($x,$y,$z)^$this->low;return $this->xoroshiro?new XoroshiroRandom($seed,$this->high):new LegacyRandom($seed);
    }
    public function fromSeed(int $seed):RandomSource {return $this->xoroshiro?new XoroshiroRandom($seed^$this->low,$seed^$this->high):new LegacyRandom($seed);}
    public function fromHashOf(string $name):RandomSource {
        if(preg_match('//u',$name)!==1){throw new \InvalidArgumentException('Invalid UTF-8 seed name');}
        if(!$this->xoroshiro){return new LegacyRandom(Int64::stringHash($name)^$this->low);}
        $parts=unpack('Jlow/Jhigh',md5($name,true));return new XoroshiroRandom($parts['low']^$this->low,$parts['high']^$this->high);
    }
}
