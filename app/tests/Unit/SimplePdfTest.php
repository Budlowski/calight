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
}
