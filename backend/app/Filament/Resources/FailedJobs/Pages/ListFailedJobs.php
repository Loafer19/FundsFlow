<?php

namespace App\Filament\Resources\FailedJobs\Pages;

use App\Filament\Resources\FailedJobs\FailedJobResource;
use Filament\Resources\Pages\ListRecords;

class ListFailedJobs extends ListRecords
{
    protected static string $resource = FailedJobResource::class;
}
