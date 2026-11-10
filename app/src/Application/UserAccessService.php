<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Persistence\UserRepository;
use App\Support\Clock;
use InvalidArgumentException;

final readonly class UserAccessService
{
    public function __construct(private UserRepository $users) {}
    /** @return list<array<string,mixed>> */ public function all():array{return$this->users->all();}
    /** @return list<array<string,mixed>> */
    public function update(string $id,array $data,string $currentUserId):array
    {
        $role=(string)($data['role']??'');$active=array_key_exists('active',$data)?filter_var($data['active'],FILTER_VALIDATE_BOOL,FILTER_NULL_ON_FAILURE):null;
        if(!in_array($role,['admin','office','technician'],true)||$active===null)throw new InvalidArgumentException('Rolle oder Aktivstatus ist ungültig.');
        if($id===$currentUserId&&!$active)throw new InvalidArgumentException('Das eigene Benutzerkonto kann nicht deaktiviert werden.');
        $users=$this->users->all();$target=null;foreach($users as $user)if((string)$user['id']===$id){$target=$user;break;}
        $activeAdmins=count(array_filter($users,static fn(array $user):bool=>$user['role']==='admin'&&(int)$user['active']===1));
        if(is_array($target)&&$target['role']==='admin'&&(int)$target['active']===1&&($role!=='admin'||!$active)&&$activeAdmins<=1)throw new InvalidArgumentException('Das letzte aktive Administratorkonto muss erhalten bleiben.');
        if(!$this->users->updateAccess($id,$role,$active,Clock::now()))throw new InvalidArgumentException('Benutzerkonto wurde nicht gefunden.');
        return$this->users->all();
    }
}
