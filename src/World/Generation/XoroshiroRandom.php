<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World\Generation;
final class XoroshiroRandom extends RandomSource
{
    private int $low;
    private int $high;
    public function __construct(int $seed,?int $high=null){if($high===null){$this->setSeed($seed);}else{$this->initialize($seed,$high);}}
    private function initialize(int $low,int $high):void {
        if(($low|$high)===0){$low=-7046029254386353131;$high=7640891576956012809;}
        $this->low=$low;$this->high=$high;$this->gaussian=null;
    }
    public function setSeed(int $seed):void {
        $low=$seed^7640891576956012809;$high=Int64::add($low,-7046029254386353131);
        $this->initialize(Int64::mixStafford13($low),Int64::mixStafford13($high));
    }
    /** @return array{int, int} the low and high state words */
    public function state():array {return [$this->low,$this->high];}
    public function nextLong():int {
        $low=$this->low;$high=$this->high;$result=Int64::add(Int64::rotateLeft(Int64::add($low,$high),17),$low);
        $high^=$low;$this->low=Int64::rotateLeft($low,49)^$high^($high<<21);$this->high=Int64::rotateLeft($high,28);return $result;
    }
    public function nextInt(?int $bound=null):int {
        if($bound===null){return Int64::signed32($this->nextLong());}self::bound($bound);
        do{$value=$this->nextLong()&0xffffffff;$product=$value*$bound;$low=$product&0xffffffff;}while($low<$bound && $low<(4294967296-$bound)%$bound);
        return $product>>32;
    }
    public function nextFloat():float {return Int64::unsignedShift($this->nextLong(),40)/16777216;}
    public function nextDouble():float {return Int64::unsignedShift($this->nextLong(),11)/9007199254740992;}
    public function nextBoolean():bool {return ($this->nextLong()&1)!==0;}
    public function fork():RandomSource {return new self($this->nextLong(),$this->nextLong());}
    public function forkPositional():PositionalRandom {return new PositionalRandom(true,$this->nextLong(),$this->nextLong());}
}
