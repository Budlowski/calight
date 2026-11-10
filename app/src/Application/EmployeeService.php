<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Calculation\Decimal;
use App\Domain\DailyReportValidationException;
use App\Infrastructure\Persistence\EmployeeRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Support\Clock;
use App\Support\Uuid;
use Brick\Math\RoundingMode;
use PDOException;

final readonly class EmployeeService
{
    public function __construct(private EmployeeRepository $employees,private UserRepository $users) {}

    /** @return array<string,mixed> */
    public function overview():array{return['employees'=>$this->employees->all(),'users'=>$this->users->all()];}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function save(?string $id,array $input):array
    {
        $name=trim((string)($input['displayName']??''));$personnel=trim((string)($input['personnelNumber']??''));$userId=trim((string)($input['userId']??''));
        if($name===''||mb_strlen($name)>150)throw new DailyReportValidationException(['Der Anzeigename ist erforderlich und darf höchstens 150 Zeichen lang sein.']);
        if(mb_strlen($personnel)>50)throw new DailyReportValidationException(['Die Personalnummer darf höchstens 50 Zeichen lang sein.']);
        try{$rate=(string)Decimal::parse((string)($input['hourlyCostRate']??''),Decimal::COST_SCALE,'Interner Stundenkostensatz')->toScale(Decimal::COST_SCALE,RoundingMode::HalfUp);}catch(\InvalidArgumentException $e){throw new DailyReportValidationException([$e->getMessage()]);}
        if(str_starts_with($rate,'-'))throw new DailyReportValidationException(['Der interne Stundenkostensatz darf nicht negativ sein.']);
        $active=filter_var($input['active']??true,FILTER_VALIDATE_BOOL);$now=Clock::now();$data=['id'=>$id?:Uuid::v4(),'user_id'=>$userId!==''?$userId:null,'personnel_number'=>$personnel!==''?$personnel:null,'display_name'=>$name,'hourly_cost_rate'=>$rate,'active'=>$active?1:0,'created_at'=>$now,'updated_at'=>$now];
        try{if($id===null)$this->employees->insert($data);elseif(!$this->employees->update($data))throw new DailyReportValidationException(['Mitarbeiter wurde nicht gefunden.']);}catch(PDOException $e){if($e->getCode()==='23000')throw new DailyReportValidationException(['Benutzerkonto oder Personalnummer ist bereits einem Mitarbeiter zugeordnet.']);throw$e;}
        return$this->employees->find($data['id'])??[];
    }
}
