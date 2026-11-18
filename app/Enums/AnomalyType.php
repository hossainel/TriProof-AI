<?php

namespace App\Enums;

enum AnomalyType: string
{
    case CloneCluster = 'clone_cluster';
    case GhostProductionAttempt = 'ghost_production_attempt';
    case ReworkGap = 'rework_gap';
    case DefectCluster = 'defect_cluster';
}
