<?php
declare(strict_types=1);
namespace PhpMinecraftJavaServer\Protocol;

/** State properties and gameplay constants extracted from the pinned Mojang JAR. */
final class Catalog
{
    public readonly array $blocks;
    public readonly array $items;
    public readonly array $flags;
    public readonly array $entityMetadata;
    public readonly int $itemMetadataIndex;
    public readonly int $itemMetadataSerializer;
    private array $itemNames = [];
    private array $stateNames = [];
    public function __construct(string $directory) {
        $bytes = file_get_contents($directory.'/gameplay.json');
        if ($bytes === false) { throw new \RuntimeException('Missing gameplay reference data'); }
        $data = json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
        $this->entityMetadata=$data['entity_metadata'];
        $this->itemMetadataIndex=$data['item_metadata_index'];$this->itemMetadataSerializer=$data['item_metadata_serializer'];
        $this->blocks=$data['blocks'];$this->items=$data['items'];$this->flags=$data['flags'];
        foreach ($this->items as $name=>$item) { $this->itemNames[$item['id']]=$name; }
        foreach ($this->blocks as $name=>$block) {
            for ($i=$block['first'],$end=$i+$block['count'];$i<$end;++$i) { $this->stateNames[$i]=$name; }
        }
    }
    public function itemName(int $id): ?string { return $this->itemNames[$id]??null; }
    public function item(int $id): ?array { $name=$this->itemName($id);return $name===null?null:$this->items[$name]; }
    public function blockName(int $state): string { return $this->stateNames[$state]??'air'; }
    public function state(string $name,array $properties=[]): int {
        $block=$this->blocks[$name]??throw new \InvalidArgumentException('Unknown block: '.$name);
        $id=$block['first'];
        foreach ($block['properties'] as $prop=>$info) {
            $value=(string)($properties[$prop]??$info['default']);
            $index=array_search($value,$info['values'],true);
            if ($index===false) { throw new \InvalidArgumentException('Invalid '.$name.' property '.$prop.'='.$value); }
            $id+=$index*$info['stride'];
        }
        return $id;
    }
    public function properties(int $state): array {
        $block=$this->blocks[$this->blockName($state)];$offset=$state-$block['first'];$result=[];
        foreach ($block['properties'] as $name=>$info) {
            $index=$info['stride']===0?0:intdiv($offset,$info['stride'])%count($info['values']);
            $result[$name]=$info['values'][$index];
        }
        return $result;
    }
    public function flag(int $state,int $flag): bool { return (($this->flags[$state]??0)&$flag)!==0; }
    public function air(int $state): bool { return $this->flag($state,1); }
    public function motionBlocking(int $state): bool { return $this->flag($state,2|4); }
    public function fluid(int $state): bool { return $this->flag($state,4); }
    public function opaque(int $state): bool { return $this->flag($state,8); }
    public function emission(int $state): int { return ($this->flags[$state]??0)>>4; }
}
