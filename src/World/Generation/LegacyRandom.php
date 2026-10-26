<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World\Generation;
final class LegacyRandom extends RandomSource
{
    private int $state;
    public function __construct(int $seed){$this->setSeed($seed);}
    public function setSeed(int $seed):void {$this->state=($seed^25214903917)&281474976710655;$this->gaussian=null;}
    public function next(int $bits):int {
        if($bits<1 || $bits>32){throw new \InvalidArgumentException('Expected 1–32 random bits');}
        $low=$this->state&0xffffff;$high=$this->state>>24;$aLow=25214903917&0xffffff;$aHigh=25214903917>>24;
        $product=$low*$aLow+11;$nextHigh=($high*$aLow+$low*$aHigh+($product>>24))&0xffffff;
        $this->state=($nextHigh<<24)|($product&0xffffff);$value=$this->state>>(48-$bits);
        return $bits===32?Int64::signed32($value):$value;
    }
    public function nextInt(?int $bound=null):int {
        if($bound===null){return $this->next(32);}self::bound($bound);
        if(($bound&($bound-1))===0){return ($bound*$this->next(31))>>31;}
        do{$bits=$this->next(31);$value=$bits%$bound;}while($bits-$value+$bound-1>=2147483648);
        return $value;
    }
    public function nextLong():int {$a=$this->next(32);$b=$this->next(32);return (($a-($b<0?1:0))<<32)|($b&0xffffffff);}
    public function nextFloat():float {return $this->next(24)/16777216;}
    public function nextDouble():float {return (($this->next(26)<<27)+$this->next(27))/9007199254740992;}
    public function nextBoolean():bool {return $this->next(1)!==0;}
    public function fork():RandomSource {return new self($this->nextLong());}
    public function forkPositional():PositionalRandom {return new PositionalRandom(false,$this->nextLong());}
}
