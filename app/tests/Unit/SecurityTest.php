<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\Request;
use App\Security\AuthorizationPolicy;
use App\Security\ConfidentialDataFilter;
use App\Security\RolePermissions;
use App\Tests\TestCase;

final class SecurityTest extends TestCase
{
    public function testRolesContainOnlyTheirFixedPermissions():void
    {
        $this->assertTrue(RolePermissions::allows('admin','users.manage'));
        $this->assertTrue(RolePermissions::allows('office','offers.write'));
        $this->assertTrue(RolePermissions::allows('office','costs.read'));
        $this->assertTrue(!RolePermissions::allows('office','users.manage'));
        $this->assertTrue(RolePermissions::allows('technician','orders.read'));
        $this->assertTrue(RolePermissions::allows('technician','reports.write'));
        $this->assertTrue(RolePermissions::allows('technician','reports.read'));
        $this->assertTrue(RolePermissions::allows('office','employees.manage'));
        $this->assertTrue(!RolePermissions::allows('technician','offers.write'));
        $this->assertTrue(!RolePermissions::allows('technician','costs.read'));
    }

    public function testRoutesRequireGranularPermissions():void
    {
        $write=new Request('PUT','/api/offers/id',[],[],[],'');
        $internalPdf=new Request('POST','/api/offers/id/calculation-pdf',[],[],[],'');
        $reportWrite=new Request('PUT','/api/daily-reports/id',[],[],[],'');
        $this->assertSame(['offers.write'],AuthorizationPolicy::required($write));
        $this->assertSame(['offers.read','costs.read'],AuthorizationPolicy::required($internalPdf));
        $this->assertSame(['reports.write'],AuthorizationPolicy::required($reportWrite));
        $denied=AuthorizationPolicy::deniedResponse($write);$this->assertSame(403,$denied->status);$this->assertTrue(str_contains($denied->body,'Keine Berechtigung'));
    }

    public function testConfidentialOfferDataIsRemovedRecursively():void
    {
        $filtered=ConfidentialDataFilter::offer(['calculation'=>['netTotal'=>'10.00','totalCosts'=>'4.00','contributionMargin'=>'6.00','analysis'=>['laborHours'=>'1.000']],'nodes'=>[['components'=>[['name'=>'Lohn','costPrice'=>'4.00','salesPrice'=>'10.00']]]]]);
        $this->assertSame('10.00',$filtered['calculation']['netTotal']);
        $this->assertTrue(!isset($filtered['calculation']['totalCosts'],$filtered['calculation']['contributionMargin'],$filtered['calculation']['analysis']));
        $this->assertTrue(!isset($filtered['nodes'][0]['components'][0]['costPrice']));
        $this->assertSame('10.00',$filtered['nodes'][0]['components'][0]['salesPrice']);
    }
}
