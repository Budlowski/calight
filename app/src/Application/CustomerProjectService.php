<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Customer\CustomerProjectValidationException;
use App\Infrastructure\Persistence\CustomerProjectRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Support\Clock;
use App\Support\Uuid;
use DateTimeImmutable;
use PDOException;
use Throwable;

final readonly class CustomerProjectService
{
    public function __construct(private CustomerProjectRepository $repository, private NumberRangeRepository $numberRanges)
    {
    }

    /** @return array<string,mixed> */
    public function overview(): array
    {
        return ['customers' => array_map($this->mapCustomer(...), $this->repository->customers()), 'projects' => array_map($this->mapProject(...), $this->repository->projects())];
    }

    /** @param array<string,mixed> $input */
    public function createCustomer(array $input): array
    {
        [$data, $contacts, $addresses] = $this->customerData($input);
        $data['id'] = Uuid::v4(); $data['copied_from_id'] = null; $data['created_at'] = $data['updated_at'] = Clock::now();
        $this->repository->begin();
        try {
            $number = $this->nullable($input, 'customerNumber', 100);
            $data['customer_number'] = $number === null
                ? $this->numberRanges->allocate('customer', 'customer', $data['id'], Uuid::v4(), new DateTimeImmutable())
                : $this->numberRanges->reserveManual('customer', 'customer', $data['id'], Uuid::v4(), $number, new DateTimeImmutable());
            $this->repository->insertCustomer($data);
            $this->repository->replaceCustomerChildren($data['id'], $this->prepareContacts($data['id'], $contacts), $this->prepareAddresses($data['id'], $addresses));
            $this->repository->commit();
        } catch (Throwable $e) { $this->repository->rollBack(); $this->writeError($e); }
        return $this->customerById($data['id']);
    }

    /** @param array<string,mixed> $input */
    public function updateCustomer(string $id, array $input): array
    {
        $current = $this->requireCustomer($id);
        [$data, $contacts, $addresses] = $this->customerData($input);
        $data['id'] = $id; $data['updated_at'] = Clock::now();
        $this->repository->begin();
        try {
            if ($current['customer_number'] === null) {
                $manual = $this->nullable($input, 'customerNumber', 100);
                $number = filter_var($input['autoCustomerNumber'] ?? false, FILTER_VALIDATE_BOOL) || $manual === null
                    ? $this->numberRanges->allocate('customer', 'customer', $id, Uuid::v4(), new DateTimeImmutable())
                    : $this->numberRanges->reserveManual('customer', 'customer', $id, Uuid::v4(), $manual, new DateTimeImmutable());
                // Nummer wird separat gesetzt, weil sie nach der ersten Vergabe unveränderlich bleibt.
                $this->repository->setCustomerNumber($id, $number);
            }
            $this->repository->updateCustomer($data);
            $this->repository->replaceCustomerChildren($id, $this->prepareContacts($id, $contacts), $this->prepareAddresses($id, $addresses));
            $this->repository->commit();
        } catch (Throwable $e) { $this->repository->rollBack(); $this->writeError($e); }
        return $this->customerById($id);
    }

    public function copyCustomer(string $id): array
    {
        $source = $this->customerById($id);
        $payload = $source;
        $payload['customerNumber'] = null;
        $payload['autoCustomerNumber'] = false;
        $payload['companyName'] = $source['companyName'] === '' ? '' : 'Kopie von ' . $source['companyName'];
        if ($source['customerType'] === 'private') $payload['lastName'] = 'Kopie von ' . $source['lastName'];
        $payload['contacts'] = array_map(static fn (array $contact): array => ['id' => null] + $contact, $source['contacts']);
        $payload['addresses'] = array_map(static fn (array $address): array => ['id' => null] + $address, $source['addresses']);
        $copy = $this->createCustomer($payload);
        return $copy;
    }

    public function setCustomerArchived(string $id, bool $archived): array
    {
        $this->requireCustomer($id);
        $this->repository->setCustomerActive($id, !$archived, Clock::now());
        return $this->customerById($id);
    }

    /** @param array<string,mixed> $input */
    public function createProject(array $input): array
    {
        $data = $this->projectData($input); $data['id'] = Uuid::v4(); $data['created_at'] = $data['updated_at'] = Clock::now();
        $this->repository->begin();
        try {
            $number = $this->nullable($input, 'projectNumber', 100);
            $data['project_number'] = $number === null
                ? $this->numberRanges->allocate('project', 'project', $data['id'], Uuid::v4(), new DateTimeImmutable())
                : $this->numberRanges->reserveManual('project', 'project', $data['id'], Uuid::v4(), $number, new DateTimeImmutable());
            $this->repository->insertProject($data); $this->repository->commit();
        } catch (Throwable $e) { $this->repository->rollBack(); $this->writeError($e); }
        return $this->projectById($data['id']);
    }

    /** @param array<string,mixed> $input */
    public function updateProject(string $id, array $input): array
    {
        $current = $this->requireProject($id); $data = $this->projectData($input); $data['id'] = $id; $data['updated_at'] = Clock::now();
        $this->repository->begin();
        try {
            if ($current['project_number'] === null) {
                $manual = $this->nullable($input, 'projectNumber', 100);
                $number = filter_var($input['autoProjectNumber'] ?? false, FILTER_VALIDATE_BOOL) || $manual === null
                    ? $this->numberRanges->allocate('project', 'project', $id, Uuid::v4(), new DateTimeImmutable())
                    : $this->numberRanges->reserveManual('project', 'project', $id, Uuid::v4(), $manual, new DateTimeImmutable());
                $this->repository->setProjectNumber($id, $number);
            }
            $this->repository->updateProject($data); $this->repository->commit();
        } catch (Throwable $e) { $this->repository->rollBack(); $this->writeError($e); }
        return $this->projectById($id);
    }

    /** @return array<string,mixed> */
    private function customerById(string $id): array { return $this->mapCustomer($this->requireCustomer($id)); }
    /** @return array<string,mixed> */
    private function projectById(string $id): array { return $this->mapProject($this->requireProject($id)); }
    private function requireCustomer(string $id): array { $row=$this->repository->customer($id); if(!$row) throw new CustomerProjectValidationException(['Kunde wurde nicht gefunden.']); return $row; }
    private function requireProject(string $id): array { $row=$this->repository->project($id); if(!$row) throw new CustomerProjectValidationException(['Projekt wurde nicht gefunden.']); return $row; }

    /** @return array{array<string,mixed>,list<array<string,mixed>>,list<array<string,mixed>>} */
    private function customerData(array $input): array
    {
        $type=(string)($input['customerType']??'company'); if(!in_array($type,['private','company'],true)) $this->fail('Ungültiger Kundentyp.');
        $company=$this->text($input,'companyName',200); $first=$this->text($input,'firstName',100); $last=$this->text($input,'lastName',100);
        if($type==='company' && $company==='') $this->fail('Der Firmenname ist erforderlich.');
        if($type==='private' && ($first==='' || $last==='')) $this->fail('Vorname und Nachname sind erforderlich.');
        $email=$this->text($input,'email',254); if($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) $this->fail('Die E-Mail-Adresse ist ungültig.');
        $website=$this->text($input,'website',500); if($website!=='' && !filter_var($website,FILTER_VALIDATE_URL)) $this->fail('Die Website-Adresse ist ungültig.');
        return [[
            'customer_type'=>$type,'company_name'=>$company,'first_name'=>$first,'last_name'=>$last,
            'street'=>$this->text($input,'street',200),'house_number'=>$this->text($input,'houseNumber',30),'postal_code'=>$this->text($input,'postalCode',20),
            'city'=>$this->text($input,'city',120),'country'=>$this->text($input,'country',100) ?: 'Deutschland','email'=>$email,
            'phone'=>$this->text($input,'phone',80),'website'=>$website,'vat_id'=>$this->text($input,'vatId',40),'notes'=>$this->text($input,'notes',10000),
        ], is_array($input['contacts']??null)?array_values($input['contacts']):[], is_array($input['addresses']??null)?array_values($input['addresses']):[]];
    }

    private function projectData(array $input): array
    {
        $customerId=$this->nullable($input,'customerId',36); $contactId=$this->nullable($input,'contactId',36);
        if($customerId!==null){ $customer=$this->requireCustomer($customerId); if(!(bool)$customer['active']) $this->fail('Ein archivierter Kunde kann nicht zugeordnet werden.'); }
        if($contactId!==null){ $contact=array_values(array_filter($this->repository->contacts((string)$customerId),fn($c)=>$c['id']===$contactId)); if(!$contact) $this->fail('Der Ansprechpartner gehört nicht zum ausgewählten Kunden.'); }
        $status=(string)($input['status']??'draft'); if(!in_array($status,['draft','active','completed','archived'],true)) $this->fail('Ungültiger Projektstatus.');
        return ['name'=>$this->required($input,'name','Projektname',200),'description'=>$this->text($input,'description',10000),
            'customer_id'=>$customerId,'contact_id'=>$contactId,'customer_name'=>$customerId===null?$this->text($input,'customerName',200):'',
            'site_street'=>$this->text($input,'siteStreet',200),'site_house_number'=>$this->text($input,'siteHouseNumber',30),
            'site_postal_code'=>$this->text($input,'sitePostalCode',20),'site_city'=>$this->text($input,'siteCity',120),
            'site_country'=>$this->text($input,'siteCountry',100) ?: 'Deutschland','site_address'=>$this->text($input,'legacySiteAddress',2000),'status'=>$status];
    }

    private function prepareContacts(string $customerId,array $rows): array
    {
        $out=[]; foreach($rows as $i=>$row){ if(!is_array($row)) $this->fail('Ansprechpartner ist ungültig.'); $first=$this->text($row,'firstName',100);$last=$this->text($row,'lastName',100);if($first===''&&$last==='')$this->fail('Jeder Ansprechpartner benötigt einen Namen.');$email=$this->text($row,'email',254);if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))$this->fail('E-Mail eines Ansprechpartners ist ungültig.');$now=Clock::now();$out[]=['id'=>Uuid::isValid((string)($row['id']??''))?(string)$row['id']:Uuid::v4(),'customer_id'=>$customerId,'salutation'=>$this->text($row,'salutation',40),'first_name'=>$first,'last_name'=>$last,'function'=>$this->text($row,'function',120),'email'=>$email,'phone'=>$this->text($row,'phone',80),'mobile'=>$this->text($row,'mobile',80),'sort_order'=>$i,'created_at'=>$now,'updated_at'=>$now]; } return $out;
    }
    private function prepareAddresses(string $customerId,array $rows): array
    {
        $out=[];foreach($rows as $i=>$row){if(!is_array($row))$this->fail('Adresse ist ungültig.');$type=(string)($row['addressType']??'other');if(!in_array($type,['invoice','site','other'],true))$this->fail('Ungültiger Adresstyp.');$now=Clock::now();$out[]=['id'=>Uuid::isValid((string)($row['id']??''))?(string)$row['id']:Uuid::v4(),'customer_id'=>$customerId,'address_type'=>$type,'label'=>$this->text($row,'label',120),'street'=>$this->text($row,'street',200),'house_number'=>$this->text($row,'houseNumber',30),'postal_code'=>$this->text($row,'postalCode',20),'city'=>$this->text($row,'city',120),'country'=>$this->text($row,'country',100)?:'Deutschland','sort_order'=>$i,'created_at'=>$now,'updated_at'=>$now];}return $out;
    }

    private function mapCustomer(array $r): array { return ['id'=>$r['id'],'customerNumber'=>$r['customer_number'],'customerType'=>$r['customer_type'],'companyName'=>$r['company_name'],'firstName'=>$r['first_name'],'lastName'=>$r['last_name'],'displayName'=>$r['customer_type']==='company'?$r['company_name']:trim($r['first_name'].' '.$r['last_name']),'street'=>$r['street'],'houseNumber'=>$r['house_number'],'postalCode'=>$r['postal_code'],'city'=>$r['city'],'country'=>$r['country'],'email'=>$r['email'],'phone'=>$r['phone'],'website'=>$r['website'],'vatId'=>$r['vat_id'],'notes'=>$r['notes'],'active'=>(bool)$r['active'],'copiedFromId'=>$r['copied_from_id'],'contacts'=>array_map($this->mapContact(...),$this->repository->contacts($r['id'])),'addresses'=>array_map($this->mapAddress(...),$this->repository->addresses($r['id']))]; }
    private function mapContact(array $r): array { return ['id'=>$r['id'],'salutation'=>$r['salutation'],'firstName'=>$r['first_name'],'lastName'=>$r['last_name'],'function'=>$r['function'],'email'=>$r['email'],'phone'=>$r['phone'],'mobile'=>$r['mobile']]; }
    private function mapAddress(array $r): array { return ['id'=>$r['id'],'addressType'=>$r['address_type'],'label'=>$r['label'],'street'=>$r['street'],'houseNumber'=>$r['house_number'],'postalCode'=>$r['postal_code'],'city'=>$r['city'],'country'=>$r['country']]; }
    private function mapProject(array $r): array { return ['id'=>$r['id'],'projectNumber'=>$r['project_number'],'name'=>$r['name'],'description'=>$r['description'],'customerId'=>$r['customer_id'],'contactId'=>$r['contact_id'],'customerName'=>$r['customer_id']!==null?($r['linked_customer_name']??''):$r['customer_name'],'contactName'=>$r['contact_name']??'','siteStreet'=>$r['site_street'],'siteHouseNumber'=>$r['site_house_number'],'sitePostalCode'=>$r['site_postal_code'],'siteCity'=>$r['site_city'],'siteCountry'=>$r['site_country'],'legacySiteAddress'=>$r['site_address'],'status'=>$r['status']]; }

    private function text(array $a,string $k,int $max): string { $v=trim((string)($a[$k]??'')); if(mb_strlen($v)>$max)$this->fail("Feld {$k} ist zu lang."); return $v; }
    private function nullable(array $a,string $k,int $max): ?string { $v=$this->text($a,$k,$max); return $v===''?null:$v; }
    private function required(array $a,string $k,string $label,int $max): string { $v=$this->text($a,$k,$max);if($v==='')$this->fail($label.' ist erforderlich.');return $v; }
    private function fail(string $message): never { throw new CustomerProjectValidationException([$message]); }
    private function writeError(Throwable $e): never { if($e instanceof CustomerProjectValidationException)throw $e;if($e instanceof PDOException && str_contains(strtolower($e->getMessage()),'unique'))$this->fail('Die vergebene Nummer wird bereits verwendet.');throw $e; }
}
