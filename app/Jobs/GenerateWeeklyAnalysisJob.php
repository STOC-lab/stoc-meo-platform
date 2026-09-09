<?php

namespace App\Jobs;

use App\Enums\AnalysisType;

/**
 * A week's reading of a store front's figures. Granted by
 * ai.weekly_analysis.enabled, which is STANDARD and above.
 */
class GenerateWeeklyAnalysisJob extends GenerateAnalysisJob
{
    public function type(): AnalysisType
    {
        return AnalysisType::Weekly;
    }
}
