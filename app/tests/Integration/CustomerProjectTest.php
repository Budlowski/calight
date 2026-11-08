<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\CustomerProjectService;
use App\Domain\Customer\CustomerProjectValidationException;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Migrator;
use App\Infrastructure\Persistence\CustomerProjectRepository;
use App\Infrastructure\Persistence\NumberRangeRepository;
use App\Tests\TestCase;
use PDO;
use PDOException;

final class CustomerProjectTest extends TestCase
{
    public function testCustomerContactsAddressesNumbersAndProjectAssignment(): void
    {
        [$service,$pdo,$temporary]=$this->service();
        try {
            $customer=$service->createCustomer([
                'customerType'=>'company','companyName'=>'Muster Elektro GmbH','street'=>'Werkstraße','houseNumber'=>'12',
                'postalCode'=>'12345','city'=>'Musterstadt','country'=>'Deutschland','email'=>'info@example.test',
                'contacts'=>[
                    ['firstName'=>'Erika','lastName'=>'Muster','function'=>'Bauleitung','email'=>'erika@example.test','phone'=>'0123'],
                    ['firstName'=>'Max','lastName'=>'Muster','function'=>'Einkauf'],
                ],
                'addresses'=>[
                    ['addressType'=>'invoice','label'=>'Buchhaltung','street'=>'Rechnungsweg','houseNumber'=>'3','postalCode'=>'12345','city'=>'Musterstadt','country'=>'Deutschland'],
                    ['addressType'=>'site','label'=>'Halle 2','street'=>'Baustellenweg','houseNumber'=>'8','postalCode'=>'54321','city'=>'Bauort','country'=>'Deutschland'],
                ],
            ]);
            $this->assertSame('10003',$customer['customerNumber']);
            $this->assertSame(2,count($customer['contacts']),'Ansprechpartner wurden beim Anlegen nicht gespeichert.');
            $this->assertSame(2,count($customer['addresses']),'Adressen wurden beim Anlegen nicht gespeichert.');

            $manual=$service->createCustomer(['customerNumber'=>'12000','customerType'=>'private','firstName'=>'Hans','lastName'=>'Handwerk']);
            $this->assertSame('12000',$manual['customerNumber']);
            $this->assertThrows(CustomerProjectValidationException::class,fn()=> $service->createCustomer(['customerNumber'=>'12000','customerType'=>'company','companyName'=>'Doppelt']));

            $project=$service->createProject([
                'name'=>'Umbau Werkhalle','description'=>'Unterverteilung erneuern','customerId'=>$customer['id'],
                'contactId'=>$customer['contacts'][0]['id'],'siteStreet'=>'Baustellenweg','siteHouseNumber'=>'8',
                'sitePostalCode'=>'54321','siteCity'=>'Bauort','siteCountry'=>'Deutschland','status'=>'active',
            ]);
            $this->assertTrue(str_starts_with($project['projectNumber'],'PR'));
            $this->assertSame($customer['id'],$project['customerId']);
            $this->assertSame('Bauort',$project['siteCity']);

            $customer['companyName']='Muster Elektro & Technik GmbH';
            $customer['contacts'][0]['phone']='0999';
            $updated=$service->updateCustomer($customer['id'],$customer);
            $this->assertSame('Muster Elektro & Technik GmbH',$updated['companyName']);
            $this->assertSame('0999',$updated['contacts'][0]['phone']);
            $reloadedProject=array_values(array_filter($service->overview()['projects'],fn($p)=>$p['id']===$project['id']))[0];
            $this->assertSame($customer['contacts'][0]['id'],$reloadedProject['contactId']);

            $changed=$service->updateProject($project['id'],[
                'projectNumber'=>$project['projectNumber'],'name'=>'Umbau Werkhalle Süd','description'=>'','customerId'=>$manual['id'],
                'contactId'=>null,'siteStreet'=>'Neuer Weg','siteHouseNumber'=>'1','sitePostalCode'=>'11111','siteCity'=>'Neuort','siteCountry'=>'Deutschland','status'=>'completed',
            ]);
            $this->assertSame($manual['id'],$changed['customerId']);
            $this->assertSame('completed',$changed['status']);
            $this->assertSame(false,$service->setCustomerArchived($customer['id'],true)['active']);
            $this->assertThrows(PDOException::class,function()use($pdo,$manual){$s=$pdo->prepare('DELETE FROM customers WHERE id=:id');$s->execute(['id'=>$manual['id']]);});

            $copy=$service->copyCustomer($customer['id']);
            $this->assertSame('10004',$copy['customerNumber']);
            $this->assertSame(2,count($copy['contacts']),'Ansprechpartner wurden beim Kopieren nicht übernommen.');
        } finally { $this->removeTree($temporary); }
    }

    /** @return array{CustomerProjectService,PDO,string} */
    private function service(): array
    {
        $root=dirname(__DIR__,2);$temporary=sys_get_temp_dir().'/kalkulation-customer-test-'.bin2hex(random_bytes(8));mkdir($temporary,0770,true);
        $pdo=ConnectionFactory::create($temporary.'/app.sqlite');(new Migrator($pdo,$root.'/migrations'))->migrate();
        return [new CustomerProjectService(new CustomerProjectRepository($pdo),new NumberRangeRepository($pdo)),$pdo,$temporary];
    }
    private function removeTree(string $path): void { if(!is_dir($path))return;foreach(scandir($path)?:[] as $item){if($item==='.'||$item==='..')continue;$child=$path.'/'.$item;is_dir($child)?$this->removeTree($child):unlink($child);}rmdir($path); }
}
