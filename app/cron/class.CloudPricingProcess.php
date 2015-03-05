<?php

use Scalr\Service\Aws;
use Scalr\Stats\CostAnalytics\Entity\PriceEntity;
use Scalr\Stats\CostAnalytics\Entity\SettingEntity;

/**
 * @deprecated It has been deprecated since 01.10.2014 because of implementing a new Scalr service
 * @see        \Scalr\System\Zmq\Cron\Task\CloudPricing
 */
class CloudPricingProcess implements \Scalr\System\Pcntl\ProcessInterface
{
    public $ThreadArgs;
    public $ProcessDescription = "Cloud pricing sync process";
    public $Logger;
    public $IsDaemon;

    public function __construct()
    {
        $this->Logger = Logger::getLogger(__CLASS__);
    }

    /**
     * {@inheritdoc}
     * @see \Scalr\System\Pcntl\ProcessInterface::OnStartForking()
     */
    public function OnStartForking()
    {
        if (!\Scalr::getContainer()->analytics->enabled) {
        	die("Terminating the process as Cost analytics is disabled in the config.\n");
        }

        if (SettingEntity::getValue(SettingEntity::ID_FORBID_AUTOMATIC_UPDATE_AWS_PRICES)) {
            die("Terminating the process because of overriding AWS prices has been forbidden by financial admin.\n");
        }

        $now = new DateTime('now', new DateTimeZone('UTC'));

        $urls = array(
            'https://a0.awsstatic.com/pricing/1/ec2/linux-od.min.js',
            'https://a0.awsstatic.com/pricing/1/ec2/mswin-od.min.js',
            'https://a0.awsstatic.com/pricing/1/ec2/previous-generation/linux-od.min.js',
            'https://a0.awsstatic.com/pricing/1/ec2/previous-generation/mswin-od.min.js',
        );

        $mapping = [
            'us-east'        => 'us-east-1',
            'us-west'        => 'us-west-1',
            'us-east-1'      => 'us-east-1',
            'us-west-1'      => 'us-west-1',
            'us-west-2'      => 'us-west-2',
            'eu-ireland'     => 'eu-west-1',
            'eu-west-1'      => 'eu-west-1',
            'eu-central-1'   => 'eu-central-1',
            'sa-east-1'      => 'sa-east-1',
            'apac-sin'       => 'ap-southeast-1',
            'ap-southeast-1' => 'ap-southeast-1',
            'apac-tokyo'     => 'ap-northeast-1',
            'ap-northeast-1' => 'ap-northeast-1',
            'apac-syd'       => 'ap-southeast-2',
            'ap-southeast-2' => 'ap-southeast-2',
            'us-gov-west-1'  => 'us-gov-west-1',
        ];

        $availableLocations = Aws::getCloudLocations();

        foreach ($urls as $link) {
            $json = trim(preg_replace('/^.+?callback\((.+?)\);\s*$/sU', '\\1', file_get_contents($link)));
            
            $os = (strpos($link, 'linux') !== false ? PriceEntity::OS_LINUX : PriceEntity::OS_WINDOWS);

            $data = json_decode(preg_replace('/(\w+):/', '"\\1":', $json));

            if (!empty($data->config->regions)) {
                $cadb = Scalr::getContainer()->cadb;

                foreach ($data->config->regions as $rd) {
                    foreach ($rd->instanceTypes as $it) {
                        if (!isset($mapping[$rd->region])) {
                            throw new Exception(sprintf("Region %s does not exist in the mapping.", $rd->region));
                        }

                        $region = $mapping[$rd->region];

                        $latest = array();

                        //Gets latest prices for all instance types from current region.
                        $res = $cadb->Execute("
                            SELECT p.instance_type, ph.applied, p.os, p.name, HEX(p.price_id) `price_id`, p.cost
                            FROM price_history ph
                            JOIN prices p ON p.price_id = ph.price_id
                            LEFT JOIN price_history ph2 ON ph2.platform = ph.platform
                                AND ph2.cloud_location = ph.cloud_location
                                AND ph2.account_id = ph.account_id
                                AND ph2.url = ph.url
                                AND ph2.applied > ph.applied AND ph2.applied <= ?
                            LEFT JOIN prices p2 ON p2.price_id = ph2.price_id
                                AND p2.instance_type = p.instance_type
                                AND p2.os = p.os
                            WHERE ph.account_id = 0 AND p2.price_id IS NULL
                            AND ph.platform = 'ec2'
                            AND ph.cloud_location = ?
                            AND ph.url = ''
                            AND ph.applied <= ?
                        ", array(
                            $now->format('Y-m-d'),
                            $region,
                            $now->format('Y-m-d')
                        ));
                        while ($rec = $res->FetchRow()) {
                            $latest[$rec['instance_type']][$rec['os']] = array(
                            	'applied' => $rec['applied'],
                                'price_id' => $rec['price_id'],
                                'cost' => $rec['cost'],
                            );
                        }

                        $upd = array();
                        $needUpdate = false;
                        foreach ($it->sizes as $sz) {
                            foreach ($sz->valueColumns as $v) {
                                if (!is_numeric($v->prices->USD) || $v->prices->USD < 0.000001) {
                                    continue;
                                }

                                if (!isset($latest[$sz->size][$os])) {
                                    $needUpdate = true;
                                } else if (abs(($latest[$sz->size][$os]['cost'] - $v->prices->USD)/$v->prices->USD) > 0.000001) {
                                    $needUpdate = true;
                                    $latest[$sz->size][$os]['cost'] = !is_null($v->prices->USD) ? $v->prices->USD : 0.000000;
                            	} else continue;

                                $latest[$sz->size][$os] = array(
                                    'cost' => !is_null($v->prices->USD) ? $v->prices->USD : 0.000000,
                                );
                            }
                        }
                        if ($needUpdate) {
                            $priceid = $cadb->GetOne("
                                SELECT HEX(`price_id`) AS `price_id`
                                FROM price_history
                                WHERE platform = 'ec2'
                                AND url = ''
                                AND cloud_location = ?
                                AND applied = ?
                                AND account_id = 0
                                LIMIT 1
                            ", array(
                            	$region,
                                $now->format('Y-m-d')
                            ));
                            if (!$priceid) {
                                $priceid = str_replace('-', '', Scalr::GenerateUID());
                                $cadb->Execute("
                                    INSERT price_history
                                    SET price_id = UNHEX(?),
                                        platform = 'ec2',
                                        url = '',
                                        cloud_location = ?,
                                        account_id = 0,
                                        applied = ?,
                                        deny_override = 0
                                ", array(
                                    $priceid,
                                    $region,
                                    $now->format('Y-m-d')
                                ));
                            }

                            foreach ($latest as $instanceType => $ld) {
                                foreach ($ld as $os => $v) {
                                    $cadb->Execute("
                                        REPLACE prices
                                        SET price_id = UNHEX(?),
                                            instance_type = ?,
                                            name = ?,
                                            os = ?,
                                            cost = ?
                                    ", array(
                                        $priceid,
                                        $instanceType,
                                        $instanceType,
                                        $os,
                                        $v['cost']
                                    ));
                                }
                            }
                        }
                    }
                }
            }
        }

        exit();
    }

    /**
     * {@inheritdoc}
     * @see \Scalr\System\Pcntl\ProcessInterface::OnEndForking()
     */
    public function OnEndForking()
    {
    }

    /**
     * {@inheritdoc}
     * @see \Scalr\System\Pcntl\ProcessInterface::StartThread()
     */
    public function StartThread($id)
    {
    }
}
