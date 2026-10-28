<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World;

/**
 * Noise::sample3 along one vertical column. The X/Z lattice terms are fixed for the column and
 * the corner values only change when Y enters another lattice cell, so both are reused; every
 * returned value is computed with exactly the arithmetic of Noise::sample3.
 */
final class NoiseColumn
{
    private ?int $cell=null;
    private float $a=0.0;
    private float $b=0.0;
    private float $c=0.0;
    private float $d=0.0;
    public function __construct(private readonly int $x0,private readonly int $z0,private readonly float $fx,private readonly float $fz) {}
    public function at(float $y): float {
        // Noise::fade and Noise::lerp are inlined in this per-block path, with identical expressions.
        $iy=(int)floor($y);$v=$y-$iy;$fy=$v*$v*$v*($v*($v*6-15)+10);
        if($iy!==$this->cell){
            $x1=$this->x0+374761393;$y0=$iy*1442695041;$y1=$y0+1442695041;$z1=$this->z0+668265263;
            $this->a=Noise::lerp(Noise::node($this->x0+$y0+$this->z0),Noise::node($x1+$y0+$this->z0),$this->fx);
            $this->b=Noise::lerp(Noise::node($this->x0+$y1+$this->z0),Noise::node($x1+$y1+$this->z0),$this->fx);
            $this->c=Noise::lerp(Noise::node($this->x0+$y0+$z1),Noise::node($x1+$y0+$z1),$this->fx);
            $this->d=Noise::lerp(Noise::node($this->x0+$y1+$z1),Noise::node($x1+$y1+$z1),$this->fx);
            $this->cell=$iy;
        }
        $ab=$this->a+($this->b-$this->a)*$fy;$cd=$this->c+($this->d-$this->c)*$fy;
        return $ab+($cd-$ab)*$this->fz;
    }
}
