<?php

declare(strict_types=1);

namespace App\Domain\Offer;

final class OfferStructure
{
    /**
     * @param list<array<string,mixed>> $nodes
     * @return list<array{id:string,type:string,number:string,depth:int}>
     */
    public function numbering(array $nodes): array
    {
        $result=[];
        $this->walk($nodes, [], 0, $result);
        return $result;
    }

    /** @param list<array<string,mixed>> $nodes @param list<int> $titlePath @param list<array{id:string,type:string,number:string,depth:int}> $result */
    private function walk(array $nodes, array $titlePath, int $depth, array &$result): void
    {
        $titleCounter=0; $positionCounter=0;
        foreach($nodes as $node){
            if(($node['type']??null)==='title'){
                $titleCounter++; $path=[...$titlePath,$titleCounter]; $number=implode('.',$path);
                $result[]=['id'=>(string)($node['id']??''),'type'=>'title','number'=>$number,'depth'=>$depth];
                $this->walk(is_array($node['children']??null)?$node['children']:[],$path,$depth+1,$result);
            }else{
                $positionCounter++; $suffix=str_pad((string)$positionCounter,2,'0',STR_PAD_LEFT);
                $result[]=['id'=>(string)($node['id']??''),'type'=>'position','number'=>$titlePath===[]?$suffix:implode('.',$titlePath).'.'.$suffix,'depth'=>$depth];
            }
        }
    }
}
