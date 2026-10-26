<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\World\Generation;

/*
 * Logarithm adapted from fdlibm e_log.c (https://netlib.org/fdlibm/e_log.c).
 * Copyright (C) 1993 by Sun Microsystems, Inc. All rights reserved.
 * Developed at SunSoft, a Sun Microsystems, Inc. business.
 * Permission to use, copy, modify, and distribute this software is freely
 * granted, provided that this notice is preserved.
 */
final class StableMath
{
    public static function log(float $x):float {
        if($x==0){return -INF;}if($x<0){return NAN;}if(!is_finite($x)){return $x+$x;}
        $words=unpack('Nhigh/Nlow',pack('E',$x));$high=$words['high'];$low=$words['low'];$k=0;
        if($high<0x00100000){$k-=54;$x*=1.80143985094819840000e16;$words=unpack('Nhigh/Nlow',pack('E',$x));$high=$words['high'];$low=$words['low'];}
        $k+=($high>>20)-1023;$high&=0x000fffff;$i=($high+0x95f64)&0x100000;
        $x=unpack('E',pack('NN',$high|($i^0x3ff00000),$low))[1];$k+=$i>>20;$f=$x-1;
        $ln2High=6.93147180369123816490e-1;$ln2Low=1.90821492927058770002e-10;
        if((0x000fffff&(2+$high))<3){
            if($f==0){return $k===0?0.0:$k*$ln2High+$k*$ln2Low;}
            $r=$f*$f*(0.5-0.33333333333333333*$f);return $k===0?$f-$r:$k*$ln2High-(($r-$k*$ln2Low)-$f);
        }
        $s=$f/(2+$f);$z=$s*$s;$w=$z*$z;
        $t1=$w*(3.999999999940941908e-1+$w*(2.222219843214978396e-1+$w*1.531383769920937332e-1));
        $t2=$z*(6.666666666666735130e-1+$w*(2.857142874366239149e-1+$w*(1.818357216161805012e-1+$w*1.479819860511658591e-1)));
        $r=$t2+$t1;
        if((($high-0x6147a)|(0x6b851-$high))>0){$half=0.5*$f*$f;return $k===0?$f-($half-$s*($half+$r)):$k*$ln2High-(($half-($s*($half+$r)+$k*$ln2Low))-$f);}
        return $k===0?$f-$s*($f-$r):$k*$ln2High-(($s*($f-$r)-$k*$ln2Low)-$f);
    }
}
