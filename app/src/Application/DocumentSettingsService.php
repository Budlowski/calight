<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\Persistence\DocumentSettingsRepository;
use InvalidArgumentException;

final readonly class DocumentSettingsService
{
    public function __construct(private DocumentSettingsRepository $repository) {}
    /** @return array<string,mixed> */
    public function get(): array { return $this->repository->get(); }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function save(array $input): array
    {
        $current=$this->get();
        foreach(array_keys($current) as $key){if($key!=='logoData'&&array_key_exists($key,$input))$current[$key]=trim((string)$input[$key]);}
        if(($current['activeTemplate']??'standard')!=='standard')throw new InvalidArgumentException('Die gewählte Dokumentvorlage ist nicht verfügbar.');
        if(!preg_match('/^#[0-9a-fA-F]{6}$/',(string)$current['accentColor']))throw new InvalidArgumentException('Die Akzentfarbe muss als Hex-Farbe angegeben werden.');
        if(!preg_match('/^(0|[1-9][0-9]?)([.,][0-9]{1,2})?$/',(string)($current['vatRate']??'')))throw new InvalidArgumentException('Der Umsatzsteuersatz ist ungültig.');
        $current['vatRate']=str_replace(',','.',(string)$current['vatRate']);
        if(array_key_exists('logoData',$input))$current['logoData']=$this->validateLogo($input['logoData']);
        $this->repository->save($current);return $current;
    }

    private function validateLogo(mixed $value): ?string
    {
        if($value===null||$value==='')return null;
        if(!is_string($value)||strlen($value)>1_500_000||!preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$#',$value,$match))throw new InvalidArgumentException('Das Logo muss eine PNG- oder JPEG-Datei mit höchstens 1 MB sein.');
        $binary=base64_decode($match[2],true);if($binary===false||strlen($binary)>1_000_000)throw new InvalidArgumentException('Das Logo ist ungültig oder zu groß.');
        $png=str_starts_with($binary,"\x89PNG\r\n\x1a\n");$jpg=str_starts_with($binary,"\xff\xd8\xff");
        if(!$png&&!$jpg)throw new InvalidArgumentException('Der Inhalt des Logos entspricht nicht dem angegebenen Bildformat.');
        return $value;
    }
}
