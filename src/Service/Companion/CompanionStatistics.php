<?php

namespace App\Service\Companion;

use App\Service\Redis\Redis;
use XIVAPI\XIVAPI;

class CompanionStatistics
{
    /** @var XIVAPI */
    private $xivapi;
    
    public function __construct()
    {
        $this->xivapi = new XIVAPI();
    }
    
    /**
     * Get market stats
     */
    public function stats()
    {
        // get market status
        $apiStats = Redis::Cache()->get('mogboard_companion_update_stats');
        
        if ($apiStats == null) {
            $apiStats = $this->xivapi->market->stats();
            Redis::Cache()->set('mogboard_companion_update_stats', $apiStats);
        }
        
        return $apiStats;
    }
    
    /**
     * Get the cheapest NQ and HQ prices from the market
     */
    public function cheapest($market)
    {
        $cheapest = [];
        
        foreach ($market as $server => $serverMarket) {
            $cheapestHq = 0;
            $cheapestNq = 0;
            
            foreach ($serverMarket->Prices as $m) {
                if ($cheapestNq === 0 && $m->IsHQ === false) {
                    $cheapestNq = $m->PricePerUnit;
                }
    
                if ($cheapestHq === 0 && $m->IsHQ === true) {
                    $cheapestHq = $m->PricePerUnit;
                }
            }
            
            $cheapest[$server] = [
                'HQ' => $cheapestHq,
                'NQ' => $cheapestNq
            ];
        }
        
        return $cheapest;
    }
}
