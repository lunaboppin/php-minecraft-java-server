<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World\Generation;
abstract class RandomSource
{
    protected ?float $gaussian=null;
    abstract public function nextInt(?int $bound=null):int;
    abstract public function nextLong():int;
    abstract public function nextFloat():float;
    abstract public function nextDouble():float;
    abstract public function nextBoolean():bool;
    abstract public function fork():RandomSource;
    abstract public function forkPositional():PositionalRandom;
    abstract public function setSeed(int $seed):void;
    public function nextGaussian():float {
        if($this->gaussian!==null){$result=$this->gaussian;$this->gaussian=null;return $result;}
        do{$x=2*$this->nextDouble()-1;$y=2*$this->nextDouble()-1;$s=$x*$x+$y*$y;}while($s>=1 || $s==0);
        $scale=sqrt(-2*StableMath::log($s)/$s);$this->gaussian=$y*$scale;return $x*$scale;
    }
    public function consumeCount(int $count):void {for($i=0;$i<$count;++$i){$this->nextInt();}}
    protected static function bound(int $bound):void {if($bound<1 || $bound>2147483647){throw new \InvalidArgumentException('Random bound must be a positive Java int');}}
}
