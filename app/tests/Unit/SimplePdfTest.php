<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Infrastructure\Pdf\SimplePdf;
use App\Tests\TestCase;

final class SimplePdfTest extends TestCase
{
    public function testCreatesMultipagePdfWithPageNumbers():void
    {
        $pdf=new SimplePdf();$pdf->text(40,800,12,'ENTWURF',true);$pdf->page();$pdf->text(40,800,10,'Zweite Seite');$output=$pdf->output();
        $this->assertTrue(str_starts_with($output,'%PDF-1.4'));
        $this->assertTrue(str_contains($output,'/Count 2'));
        $this->assertTrue(str_contains($output,'Seite 1 / 2'));
    }
    public function testContainScalingPreservesWideSquareAndPortraitRatios():void
    {
        $wide=SimplePdf::containDimensions(400,100,120,70);$this->assertSame(120.0,$wide['width']);$this->assertSame(30.0,$wide['height']);
        $square=SimplePdf::containDimensions(200,200,120,70);$this->assertSame(70.0,$square['width']);$this->assertSame(70.0,$square['height']);
        $portrait=SimplePdf::containDimensions(100,400,120,70);$this->assertSame(17.5,$portrait['width']);$this->assertSame(70.0,$portrait['height']);
    }
}
