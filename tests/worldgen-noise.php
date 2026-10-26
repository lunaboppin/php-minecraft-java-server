<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';
use PhpMinecraftJavaServer\World\Generation\{Int64,LegacyRandom,XoroshiroRandom,PerlinNoise,NormalNoise};
$fixtures=json_decode(file_get_contents(__DIR__.'/../reference/26.3/worldgen-noise.json'),true,512,JSON_THROW_ON_ERROR);$count=0;$failures=[];$gaussianDifferences=0;
function worldgenCheck(mixed $actual,mixed $expected,string $where):void {global $count,$failures;++$count;if($actual!==$expected){$failures[]=$where.' expected '.json_encode($expected).' got '.json_encode($actual);}}
foreach($fixtures['arithmetic'] as $v){$a=(int)$v['a'];$b=(int)$v['b'];worldgenCheck(bin2hex(pack('J',Int64::add($a,$b))),$v['sum'],'wrapped sum');worldgenCheck(bin2hex(pack('J',Int64::multiply($a,$b))),$v['product'],'wrapped product');}
foreach($fixtures['random'] as $v){
    $class=$v['kind']==='legacy'?LegacyRandom::class:XoroshiroRandom::class;$r=new $class((int)$v['seed']);$factory=null;
    foreach($v['operations'] as $index=>$o){
        $actual=match($o['operation']){
            'int'=>$r->nextInt(),'bounded'=>$r->nextInt($o['bound']),'boolean'=>$r->nextBoolean(),
            'long'=>bin2hex(pack('J',$r->nextLong())),'float'=>bin2hex(pack('G',$r->nextFloat())),
            'double'=>bin2hex(pack('E',$r->nextDouble())),'gaussian'=>bin2hex(pack('E',$r->nextGaussian())),
            'fork'=>bin2hex(pack('J',$r->fork()->nextLong())),
            'hash'=>bin2hex(pack('J',($factory??=$r->forkPositional())->fromHashOf($o['name'])->nextLong())),
            'position'=>bin2hex(pack('J',$factory->at(...$o['position'])->nextLong())),
            'from_seed'=>bin2hex(pack('J',$factory->fromSeed((int)$o['seed'])->nextLong())),
            'reset'=>(function()use($r,$v){$r->setSeed((int)$v['seed']);return bin2hex(pack('E',$r->nextDouble()));})(),
        };if($o['operation']==='gaussian'){if($actual!==$o['value']){++$gaussianDifferences;}$a=unpack('J',hex2bin($actual))[1];$b=unpack('J',hex2bin($o['value']))[1];worldgenCheck(abs($a-$b)<=2,true,'Gaussian JVM numeric variation');}
        worldgenCheck($actual,$o['operation']==='gaussian'?$o['value_no_log_intrinsic']:$o['value'],$v['kind'].' seed '.$v['seed'].' '.$index.' '.$o['operation']);
    }
}
foreach($fixtures['perlin'] as $v){$r=$v['kind']==='legacy'?new LegacyRandom((int)$v['seed']):new XoroshiroRandom((int)$v['seed']);$noise=new PerlinNoise($r);
    foreach($v['samples'] as $sample){worldgenCheck(bin2hex(pack('G',$noise->get(...$sample['point']))),$sample['bits'],'Perlin '.$v['kind'].' '.$v['seed'].' '.json_encode($sample['point']));}
}
foreach($fixtures['normal_samples'] as $v){
    $r=$v['kind']==='legacy'?new LegacyRandom((int)$v['seed']):new XoroshiroRandom((int)$v['seed']);$noise=new NormalNoise($r,$fixtures['normal_parameters'][$v['name']]);
    foreach($v['samples'] as $sample){worldgenCheck(bin2hex(pack('G',$noise->get(...$sample['point']))),$sample['bits'],'Normal '.$v['name'].' '.$v['kind'].' '.$v['seed'].' '.json_encode($sample['point']));worldgenCheck(bin2hex(pack('G',$noise->get2D($sample['point'][0],$sample['point'][2]))),$sample['bits_2d'],'Normal 2D '.$v['name'].' '.$v['kind'].' '.$v['seed']);}
}
foreach($fixtures['normal_validity'] as $v){
    try{new NormalNoise(new XoroshiroRandom(42),$v['parameters']);$valid=true;}catch(\InvalidArgumentException){$valid=false;}
    worldgenCheck($valid,$v['valid'],'normal parameter validity '.json_encode($v['parameters']));
}
if($failures){throw new RuntimeException(count($failures).' worldgen mismatches of '.$count." checks:\n".implode("\n",array_slice($failures,0,30)));}
echo "Gaussian reference: ".$gaussianDifferences." default-JVM differences; deterministic JVM path matches exactly\n";
echo 'PASS: '.$count." official wrapped-integer, random-stream/positional, Perlin and layered-noise checks\n";
