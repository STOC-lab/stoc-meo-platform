<?php

namespace App\Jobs;

use App\Enums\AnalysisType;

/**
 * A day's reading of a store front's figures. Granted by
 * ai.daily_analysis.enabled, which is PREMIUM only.
 */
class GenerateDailyAnalysisJob extends GenerateAnalysisJob
{
    public function type(): AnalysisType
    {
        return AnalysisType::Daily;
    }
}
