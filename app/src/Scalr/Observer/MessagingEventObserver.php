<?php
namespace Scalr\Observer;

use Scalr\Modules\PlatformFactory;
use Scalr\Modules\Platforms\Openstack\OpenstackPlatformModule;
use Scalr\Model\Entity\SshKey;
use Scalr\Model\Entity;

class MessagingEventObserver extends AbstractEventObserver
{
    public $ObserverName = 'Messaging';

    function __construct()
    {
        parent::__construct();
    }


    public function OnServiceConfigurationPresetChanged(\ServiceConfigurationPresetChangedEvent $event)
    {
        $farmRolesPresetInfo = $this->DB->GetAll("SELECT * FROM farm_role_service_config_presets WHERE
            preset_id = ? AND behavior = ?
        ", array($event->ServiceConfiguration->id, $event->ServiceConfiguration->roleBehavior));
        if (count($farmRolesPresetInfo) > 0)
        {
            $msg = new \Scalr_Messaging_Msg_UpdateServiceConfiguration(
                $event->ServiceConfiguration->roleBehavior,
                $event->ResetToDefaults,
                1
            );

            foreach ($farmRolesPresetInfo as $farmRole)
            {
                try
                {
                    $dbFarmRole = \DBFarmRole::LoadByID($farmRole['farm_roleid']);

                    foreach ($dbFarmRole->GetServersByFilter(array('status' => \SERVER_STATUS::RUNNING)) as $dbServer)
                    {
                        if ($dbServer->IsSupported("0.6"))
                            $dbServer->SendMessage($msg, false, true);
                    }
                }
                catch(\Exception $e){}
            }
        }
    }

    /**
     * @deprecated
     */
    public function OnNewMysqlMasterUp(\NewMysqlMasterUpEvent $event)
    {
        $this->sendNewMasterUpMessage($event->DBServer, $event->SnapURL, $event);
    }

    public function OnNewDbMsrMasterUp(\NewDbMsrMasterUpEvent $event)
    {
        $this->sendNewDbMsrMasterUpMessage($event->DBServer, $event);
    }

    private function sendNewDbMsrMasterUpMessage(\DBServer $newMasterServer, $event)
    {
        $dbFarmRole = $newMasterServer->GetFarmRoleObject();
        $servers = $dbFarmRole->GetServersByFilter(array('status' => array(\SERVER_STATUS::INIT, \SERVER_STATUS::RUNNING)));

        $dbType = $newMasterServer->GetFarmRoleObject()->GetRoleObject()->getDbMsrBehavior();
        $props = \Scalr_Db_Msr_Info::init($dbFarmRole, $newMasterServer, $dbType)->getMessageProperties();

        foreach ($servers as $dbServer) {

            $msg = new \Scalr_Messaging_Msg_DbMsr_NewMasterUp($dbType);
            $msg->setServerMetaData($newMasterServer);

            $msg->{$dbType} = new \stdClass();
            $msg->{$dbType}->snapshotConfig = $props->snapshotConfig;

            foreach (\Scalr_Role_Behavior::getListForFarmRole($dbFarmRole) as $behavior)
                $msg = $behavior->extendMessage($msg, $dbServer);

            $dbServer->SendMessage($msg, false, true);
        }
    }

    /**
     * @deprecated
     */
    private function sendNewMasterUpMessage($newMasterServer, $snapURL = "", \AbstractServerEvent $event) {
        $dbFarmRole = $newMasterServer->GetFarmRoleObject();
        $servers = $dbFarmRole->GetServersByFilter(array('status' => array(\SERVER_STATUS::INIT, \SERVER_STATUS::RUNNING)));

        if ($dbFarmRole->GetSetting(\Scalr_Role_DbMsrBehavior::ROLE_NO_DATA_BUNDLE_FOR_SLAVES) == 1) {
            //
            //No need to send newMasterUp because there is no data bundle from which slave can start
            //
        }

        foreach ($servers as $DBServer) {

            $msg = new \Scalr_Messaging_Msg_Mysql_NewMasterUp($snapURL);
            $msg->setServerMetaData($newMasterServer);

            $msg = \Scalr_Scripting_Manager::extendMessage($msg, $event, $newMasterServer, $DBServer);

            $msg->replPassword = $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_REPL_PASSWORD);
            $msg->rootPassword = $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_ROOT_PASSWORD);
            if ($newMasterServer->platform == \SERVER_PLATFORMS::RACKSPACE || $newMasterServer->isOpenstack()) {
                $msg->logPos = $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_LOG_POS);
                $msg->logFile = $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_LOG_FILE);

                $snapshot = \Scalr_Storage_Snapshot::init();

                try {
                    $snapshot->loadById($dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_SCALR_SNAPSHOT_ID));
                    $msg->snapshotConfig = $snapshot->getConfig();
                } catch (\Exception $e) {
                    $this->Logger->error(new \FarmLogMessage(
                        $event->DBServer->farmId,
                        "Cannot get snaphotConfig for newMysqlMasterUp message: {$e->getMessage()}",
                        !empty($event->DBServer->serverId) ? $event->DBServer->serverId : null
                    ));
                }
            }

            $DBServer->SendMessage($msg, false, true);
        }
    }

    public function OnCustomEvent(\CustomEvent $event)
    {
        $servers = \DBFarm::LoadByID($this->FarmID)->GetServersByFilter(array('status' => array(\SERVER_STATUS::INIT, \SERVER_STATUS::RUNNING)));

        $event->messageServers = count($servers);
        $event->processing = array();

        foreach ((array)$servers as $DBServer) {
            try {
                $startTime = microtime(true);

                $msg = new \Scalr_Messaging_Msg();
                $msg->setName($event->GetName());
                $msg->setServerMetaData($event->DBServer);

                $msg =\Scalr_Scripting_Manager::extendMessage($msg, $event, $event->DBServer, $DBServer);

                $extendTime = microtime(true) - $startTime;

                // Send message ONLY if there are scripts assigned to this event
                if (count($msg->scripts) > 0)
                    $DBServer->SendMessage($msg, false, true);

                $endTime = microtime(true) - $startTime;

                $event->processing[] = array($extendTime, $endTime, count($msg->scripts));
                if (!$msg)
                    throw new \Exception("Empty MSG");

            } catch (\Exception $e) {
                //TODO: Log this situation
            }
        }
    }

    public function OnHostInit(\HostInitEvent $event)
    {
        $msg = new \Scalr_Messaging_Msg_HostInitResponse(
            $event->DBServer->GetFarmObject()->GetSetting(Entity\FarmSetting::CRYPTO_KEY),
            $event->DBServer->index
        );

        $msg->cloudLocation = $event->DBServer->GetCloudLocation();
        $msg->eventId = $event->GetEventID();
        $msg->serverId = $event->DBServer->serverId;

        $dbServer = $event->DBServer;
        $dbFarmRole = $dbServer->GetFarmRoleObject();

        if ($dbFarmRole) {
            foreach (\Scalr_Role_Behavior::getListForFarmRole($dbFarmRole) as $behavior)
                $msg = $behavior->extendMessage($msg, $dbServer);
        }

        $msg->setGlobalVariables($dbServer, true, $event);

        /**
         * TODO: Move everything to\\Scalr_Db_Msr_*
         */
        if ($dbFarmRole->GetRoleObject()->hasBehavior(\ROLE_BEHAVIORS::MYSQL))
        {
            $isMaster = (int)$dbServer->GetProperty(\SERVER_PROPERTIES::DB_MYSQL_MASTER);

            $msg->mysql = (object)array(
                "replicationMaster" => $isMaster,
                "rootPassword" => $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_ROOT_PASSWORD),
                "replPassword" => $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_REPL_PASSWORD),
                "statPassword" => $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_STAT_PASSWORD),
                "logFile" => $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_LOG_FILE),
                "logPos" => $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_LOG_POS)
            );

            if ($event->DBServer->IsSupported("0.7"))
            {
                if ($dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_SCALR_VOLUME_ID) && $isMaster)
                {
                    try {
                        $volume =\Scalr_Storage_Volume::init()->loadById(
                            $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_SCALR_VOLUME_ID)
                        );

                        $msg->mysql->volumeConfig = $volume->getConfig();
                    } catch (\Exception $e) {

                    }
                }

                /***
                 * For Rackspace we ALWAYS need snapsjot_config for mysql
                 * ***/
                if ($dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_SCALR_SNAPSHOT_ID))
                {
                    try {
                        $snapshotConfig =\Scalr_Storage_Snapshot::init()->loadById(
                            $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_SCALR_SNAPSHOT_ID)
                        );

                        $msg->mysql->snapshotConfig = $snapshotConfig->getConfig();
                    } catch (\Exception $e) {
                        $this->Logger->error(new \FarmLogMessage(
                            $event->DBServer->farmId,
                            "Cannot get snaphotConfig for hostInit message: {$e->getMessage()}",
                            !empty($event->DBServer->serverId) ? $event->DBServer->serverId : null
                        ));
                    }
                }

                if (!$msg->mysql->snapshotConfig && $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_SNAPSHOT_ID))
                {
                    $msg->mysql->snapshotConfig = new \stdClass();
                    $msg->mysql->snapshotConfig->type = $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_DATA_STORAGE_ENGINE);
                    $msg->mysql->snapshotConfig->id = $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_SNAPSHOT_ID);
                }

                if ($isMaster && !$msg->mysql->volumeConfig)
                {
                    $msg->mysql->volumeConfig = new \stdClass();
                    $msg->mysql->volumeConfig->type = $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_DATA_STORAGE_ENGINE);

                    if (!$dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_MASTER_EBS_VOLUME_ID))
                    {
                        if (in_array($dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_DATA_STORAGE_ENGINE), array(\MYSQL_STORAGE_ENGINE::EBS, \MYSQL_STORAGE_ENGINE::CSVOL))) {
                            $msg->mysql->volumeConfig->size = $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_EBS_VOLUME_SIZE);

                            if ($dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_DATA_STORAGE_ENGINE) == \MYSQL_STORAGE_ENGINE::EBS) {
                                $msg->mysql->volumeConfig->volumeType = $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_EBS_TYPE);
                                if ($msg->mysql->volumeConfig->volumeType == 'io1')
                                    $msg->mysql->volumeConfig->iops = $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_EBS_IOPS);
                            }
                        }
                        elseif ($dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_DATA_STORAGE_ENGINE) == \MYSQL_STORAGE_ENGINE::EPH) {


                            //$msg->mysql->volumeConfig->snap_backend = "cf://mysql-data-bundle/scalr-{$dbFarmRole->GetFarmObject()->Hash}";
                            $msg->mysql->volumeConfig->snap_backend = sprintf("cf://scalr-%s-%s/data-bundles/%s/mysql/",
                                $event->DBServer->envId,
                                $event->DBServer->GetCloudLocation(),
                                $event->DBServer->farmId
                            );

                            $msg->mysql->volumeConfig->vg = 'mysql';
                            $msg->mysql->volumeConfig->disk = new \stdClass();
                            $msg->mysql->volumeConfig->disk->type = 'loop';
                            $msg->mysql->volumeConfig->disk->size = '75%root';
                        }
                    }
                    else {
                        $msg->mysql->volumeConfig->id = $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_MASTER_EBS_VOLUME_ID);
                    }
                }
            }
            else {

                if ($isMaster)
                    $msg->mysql->volumeId = $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_MASTER_EBS_VOLUME_ID);

                $msg->mysql->snapshotId = $dbFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_SNAPSHOT_ID);
            }
        }


        // Create ssh keypair for rackspace
        if ($event->DBServer->IsSupported("0.7"))
        {
            $authSshKey = ($event->DBServer->platform == \SERVER_PLATFORMS::AZURE || $event->DBServer->isCloudstack());

            if($event->DBServer->isOpenstack()) {
                $isKeyPairsSupported = $event->DBServer
                                             ->GetEnvironmentObject()
                                             ->cloudCredentials($event->DBServer->platform)
                                             ->properties[Entity\CloudCredentialsProperty::OPENSTACK_EXT_KEYPAIRS_ENABLED];
                if ($isKeyPairsSupported != 1)
                    $authSshKey = true;
            }

            if ($authSshKey && $dbServer->osType == 'linux') {
                $sshKey = (new SshKey())->loadGlobalByFarmId(
                    $event->DBServer->envId,
                    $event->DBServer->platform,
                    $event->DBServer->GetFarmRoleObject()->CloudLocation,
                    $event->DBServer->farmId
                );

                if (!$sshKey) {
                    $keyName = "FARM-{$event->DBServer->farmId}-" . SCALR_ID;

                    $sshKey = new SshKey();
                    $sshKey->generateKeypair();

                    $sshKey->farmId = $event->DBServer->farmId;
                    $sshKey->envId = $event->DBServer->envId;
                    $sshKey->type = SshKey::TYPE_GLOBAL;
                    $sshKey->platform = $event->DBServer->platform;
                    $sshKey->cloudLocation = ($event->DBServer->isCloudstack() || $event->DBServer->platform ==\SERVER_PLATFORMS::AZURE || $event->DBServer->platform ==\SERVER_PLATFORMS::GCE) ? "" : $event->DBServer->GetFarmRoleObject()->CloudLocation;
                    $sshKey->cloudKeyName = $keyName;

                    $sshKey->save();
                }

                $sshKeysMsg = new \Scalr_Messaging_Msg_UpdateSshAuthorizedKeys(array($sshKey->publicKey), array());
                $event->DBServer->SendMessage($sshKeysMsg, false, true);
            }
        }

        // Send HostInitResponse to target server
        $event->DBServer->SendMessage($msg);

        // Send broadcast HostInit
        $servers = \DBFarm::LoadByID($this->FarmID)->GetServersByFilter(array('status' => array(\SERVER_STATUS::INIT, \SERVER_STATUS::RUNNING)));

        $event->msgExpected = count($servers);

        foreach ((array)$servers as $DBServer)
        {

            if (!$DBServer->IsSupported('0.5')) {
                $event->msgExpected--;
                continue;
            }

            if ($DBServer->status ==\SERVER_STATUS::INIT && $DBServer->serverId != $event->DBServer->serverId) {
                $event->msgExpected--;
                continue;
            }


            $hiMsg = new \Scalr_Messaging_Msg_HostInit();
            $hiMsg->setServerMetaData($event->DBServer);

            $hiMsg =\Scalr_Scripting_Manager::extendMessage($hiMsg, $event, $event->DBServer, $DBServer);

            if ($event->DBServer->farmRoleId != 0) {
                foreach (\Scalr_Role_Behavior::getListForFarmRole($event->DBServer->GetFarmRoleObject()) as $behavior)
                    $hiMsg = $behavior->extendMessage($hiMsg, $event->DBServer);
            }

            $hiMsg = $DBServer->SendMessage($hiMsg, false, true);

            if ($hiMsg->dbMessageId)
                $event->msgCreated++;
        }
    }

    public function OnResumeComplete(\ResumeCompleteEvent $event)
    {
        $servers = \DBFarm::LoadByID($this->FarmID)->GetServersByFilter(array('status' => array(\SERVER_STATUS::INIT,\SERVER_STATUS::RUNNING)));
        foreach ((array)$servers as $DBServer)
        {
            $msg = new \Scalr_Messaging_Msg_ResumeComplete();
            $msg->setServerMetaData($event->DBServer);

            $msg =\Scalr_Scripting_Manager::extendMessage($msg, $event, $event->DBServer, $DBServer);

            $delayed = !($DBServer->serverId == $event->DBServer->serverId);

            $DBServer->SendMessage($msg, false, true);
        }
    }

    public function OnIPAddressChanged(\IPAddressChangedEvent $event)
    {
        $servers = \DBFarm::LoadByID($this->FarmID)->GetServersByFilter(array('status' => array(\SERVER_STATUS::INIT,\SERVER_STATUS::RUNNING)));
        foreach ((array)$servers as $DBServer)
        {
            $msg = new \Scalr_Messaging_Msg_IpAddressChanged(
                $event->NewIPAddress,
                $event->NewLocalIPAddress
            );
            $msg->setServerMetaData($event->DBServer);

            $msg =\Scalr_Scripting_Manager::extendMessage($msg, $event, $event->DBServer, $DBServer);

            $delayed = !($DBServer->serverId == $event->DBServer->serverId);

            $DBServer->SendMessage($msg, false, true);
        }
    }

    public function OnBeforeHostUp(\BeforeHostUpEvent $event) {
        $servers = \DBFarm::LoadByID($this->FarmID)->GetServersByFilter(array('status' => array(\SERVER_STATUS::INIT,\SERVER_STATUS::RUNNING)));
        foreach ((array)$servers as $DBServer)
        {

            $msg = new \Scalr_Messaging_Msg_BeforeHostUp();
            $msg->setServerMetaData($event->DBServer);

            $msg =\Scalr_Scripting_Manager::extendMessage($msg, $event, $event->DBServer, $DBServer);

            $delayed = !($DBServer->serverId == $event->DBServer->serverId);
            $delayed = true;

            $DBServer->SendMessage($msg, false, true);
        }
    }

    public function OnEBSVolumeAttached(\EBSVolumeAttachedEvent $event)
    {
        $servers = \DBFarm::LoadByID($this->FarmID)->GetServersByFilter(array('status' => array(\SERVER_STATUS::INIT,\SERVER_STATUS::RUNNING)));
        foreach ((array)$servers as $DBServer)
        {
            $msg = new \Scalr_Messaging_Msg_BlockDeviceAttached(
                $event->VolumeID,
                $event->DeviceName
            );
            $msg->setServerMetaData($event->DBServer);

            $msg =\Scalr_Scripting_Manager::extendMessage($msg, $event, $event->DBServer, $DBServer);

            $DBServer->SendMessage($msg, false, true);
        }
    }

    public function OnEBSVolumeMounted(\EBSVolumeMountedEvent $event)
    {
        $servers = \DBFarm::LoadByID($this->FarmID)->GetServersByFilter(array('status' => array(\SERVER_STATUS::INIT,\SERVER_STATUS::RUNNING)));
        foreach ((array)$servers as $DBServer)
        {
            $msg = new \Scalr_Messaging_Msg_BlockDeviceMounted(
                $event->VolumeID,
                $event->DeviceName,
                $event->Mountpoint,
                false,
                ''
            );
            $msg->setServerMetaData($event->DBServer);

            $msg =\Scalr_Scripting_Manager::extendMessage($msg, $event, $event->DBServer, $DBServer);

            $DBServer->SendMessage($msg, false, true);
        }
    }

    //TODO: RebootBegin

    public function OnRebootComplete(\RebootCompleteEvent $event)
    {
        $servers = \DBFarm::LoadByID($this->FarmID)->GetServersByFilter(array('status' => array(\SERVER_STATUS::INIT,\SERVER_STATUS::RUNNING)));
        foreach ((array)$servers as $DBServer)
        {
            $msg = new \Scalr_Messaging_Msg_RebootFinish();
            $msg->setServerMetaData($event->DBServer);

            $msg =\Scalr_Scripting_Manager::extendMessage($msg, $event, $event->DBServer, $DBServer);

            $DBServer->SendMessage($msg, false, true);
        }
    }

    public function OnHostUp(\HostUpEvent $event)
    {
        $servers = \DBFarm::LoadByID($this->FarmID)->GetServersByFilter(array('status' => array(\SERVER_STATUS::INIT,\SERVER_STATUS::RUNNING)));

        $event->msgExpected = count($servers);

        foreach ((array)$servers as $DBServer)
        {
            try {
                $msg = new \Scalr_Messaging_Msg_HostUp();
                $msg->setServerMetaData($event->DBServer);
                $msg->roleName = $event->DBServer->GetFarmRoleObject()->GetRoleObject()->name;

                $msg =\Scalr_Scripting_Manager::extendMessage($msg, $event, $event->DBServer, $DBServer);

                if ($event->DBServer->farmRoleId != 0) {
                    foreach (\Scalr_Role_Behavior::getListForFarmRole($event->DBServer->GetFarmRoleObject()) as $behavior)
                        $msg = $behavior->extendMessage($msg, $event->DBServer);
                }

                $msg = $DBServer->SendMessage($msg, false, true);

                if ($msg)
                    $event->msgCreated++;
                else
                    throw new \Exception("Empty MSG: {$DBServer->serverId} ({$event->DBServer->serverId})");

            } catch (\Exception $e) {
                \Scalr::getContainer()->logger(__CLASS__)->fatal("MessagingEventObserver::OnHostUp failed: {$e->getMessage()}");
            }
        }

        if ($event->DBServer->GetProperty(\SERVER_PROPERTIES::DB_MYSQL_MASTER) == 1 && $event->DBServer->IsSupported("0.7")) {
            $this->sendNewMasterUpMessage($event->DBServer, "", $event);
        }

        if ($event->DBServer->GetProperty(\Scalr_Db_Msr::REPLICATION_MASTER) == 1) {
            $this->sendNewDbMsrMasterUpMessage($event->DBServer, $event);
        }
    }

    public function OnBeforeHostTerminate(\BeforeHostTerminateEvent $event)
    {
        try {
            $dbFarm = \DBFarm::LoadByID($this->FarmID);
        } catch (\Exception $e) {}

        if ($dbFarm) {
            
            $servers = $this->DB->Execute("SELECT * FROM servers WHERE farm_id = ? AND status IN (?,?,?,?)", array(
                $dbFarm->ID,
                \SERVER_STATUS::INIT,\SERVER_STATUS::RUNNING,\SERVER_STATUS::PENDING_TERMINATE,\SERVER_STATUS::PENDING_SUSPEND
            ));
            
            $event->messageLongestInsert = 0;
            
            while ($server = $servers->FetchRow()) {
                
                $DBServer = \DBServer::load($server);
                
                // We don't need to send beforeHostTerminate event to all "Pending terminate" servers,
                // only tu eventServer.
                if ($DBServer->status ==\SERVER_STATUS::PENDING_TERMINATE) {
                    if ($DBServer->serverId != $event->DBServer->serverId)
                        continue;
                }

                $msg = new \Scalr_Messaging_Msg_BeforeHostTerminate();
                $msg->setServerMetaData($event->DBServer);
                $msg->suspend = $event->suspend;

                $msg =\Scalr_Scripting_Manager::extendMessage($msg, $event, $event->DBServer, $DBServer);

                if ($event->DBServer->farmRoleId != 0) {
                    foreach (\Scalr_Role_Behavior::getListForFarmRole($event->DBServer->GetFarmRoleObject()) as $behavior)
                        $msg = $behavior->extendMessage($msg, $event->DBServer);
                }

                $mt = microtime(true);
                $DBServer->SendMessage($msg, false, true);
                $mtResult = microtime(true) - $mt;
                if ($event->messageLongestInsert < $mtResult)
                    $event->messageLongestInsert = $mtResult;
            }

            try {
                if ($event->DBServer->GetFarmRoleObject()->GetSetting(\Scalr_Db_Msr::SLAVE_TO_MASTER) != 1) {
                    if ($event->DBServer->GetFarmRoleObject()->GetRoleObject()->getDbMsrBehavior()) {
                        $this->sendPromoteToMasterMessage($event);
                    }
                }
            } catch (\Exception $e) {

            }
        }
    }

    public function OnBeforeInstanceLaunch(\BeforeInstanceLaunchEvent $event)
    {
        $servers = \DBFarm::LoadByID($this->FarmID)->GetServersByFilter(array('status' => array(\SERVER_STATUS::RUNNING)));
        foreach ($servers as $DBServer)
        {
            $msg = new \Scalr_Messaging_Msg_BeforeInstanceLaunch();
            $msg->setServerMetaData($event->DBServer);

            $msg =\Scalr_Scripting_Manager::extendMessage($msg, $event, $event->DBServer, $DBServer);

            $DBServer->SendMessage($msg, false, true);
        }
    }

    private function sendPromoteToMasterMessage(\AbstractServerEvent $event)
    {
        if ($event->DBServer->GetProperty(\Scalr_Db_Msr::REPLICATION_MASTER) ||
            $event->DBServer->GetFarmRoleObject()->GetSetting(\Scalr_Db_Msr::SLAVE_TO_MASTER)
        ) {
            //Check if master already running: do not send promote_to_master

            $msg = new \Scalr_Messaging_Msg_DbMsr_PromoteToMaster();
            $msg->tmpEventName = $event->GetName();
            $msg->dbType = $event->DBServer->GetFarmRoleObject()->GetRoleObject()->getDbMsrBehavior();
            $msg->cloudLocation = $event->DBServer->GetCloudLocation();

            if (in_array($event->DBServer->platform, array(\SERVER_PLATFORMS::EC2,\SERVER_PLATFORMS::CLOUDSTACK,\SERVER_PLATFORMS::IDCF))) {
                try {
                    $volume =\Scalr_Storage_Volume::init()->loadById(
                        $event->DBServer->GetFarmRoleObject()->GetSetting(\Scalr_Db_Msr::VOLUME_ID)
                    );

                    $msg->volumeConfig = $volume->getConfig();
                } catch (\Exception $e) {
                    $this->Logger->error(new \FarmLogMessage(
                        $event->DBServer->farmId,
                        "Cannot create volumeConfig for PromoteToMaster message: {$e->getMessage()}",
                        !empty($event->DBServer->serverId) ? $event->DBServer->serverId : null
                    ));
                }
            }

            if ($event->DBServer->farmRoleId != 0) {
                foreach (\Scalr_Role_Behavior::getListForRole($event->DBServer->GetFarmRoleObject()->GetRoleObject()) as $behavior)
                    $msg = $behavior->extendMessage($msg, $event->DBServer);
            }

            // Send Mysql_PromoteToMaster to the first server in the same avail zone as old master (if exists)
            // Otherwise send to first in role
            $platform = $event->DBServer->platform;
            if ($platform ==\SERVER_PLATFORMS::EC2) {
                $availZone = $event->DBServer->GetProperty(\EC2_SERVER_PROPERTIES::AVAIL_ZONE);
            }

            $dbFarmRole = $event->DBServer->GetFarmRoleObject();

            $servers = $dbFarmRole->GetServersByFilter(array('status' => array(\SERVER_STATUS::RUNNING)));
            $firstInRoleServer = false;
            foreach ($servers as $DBServer) {

                if ($DBServer->serverId == $event->DBServer->serverId)
                    continue;

                if (!$firstInRoleServer)
                    $firstInRoleServer = $DBServer;

                if (($platform ==\SERVER_PLATFORMS::EC2 && $DBServer->GetProperty(\EC2_SERVER_PROPERTIES::AVAIL_ZONE) == $availZone) || $platform !=\SERVER_PLATFORMS::EC2) {
                    $event->DBServer->SetProperty(\Scalr_Db_Msr::REPLICATION_MASTER, 0);
                    $dbFarmRole->SetSetting(\Scalr_Db_Msr::SLAVE_TO_MASTER, 1);
                    $DBServer->SetProperty(\Scalr_Db_Msr::REPLICATION_MASTER, 1);
                    $DBServer->SendMessage($msg, false, true);
                    return;
                }
            }

            if ($firstInRoleServer) {
                $dbFarmRole->SetSetting(\Scalr_Db_Msr::SLAVE_TO_MASTER, 1);
                $firstInRoleServer->SetProperty(\Scalr_Db_Msr::REPLICATION_MASTER, 1);
                $firstInRoleServer->SendMessage($msg, false, true);
            }
        }
    }

    public function OnHostDown(\HostDownEvent $event)
    {
        if ($event->DBServer->IsRebooting() == 1) {
            $event->exit = 'reboot';
            return;
        }

        if (!$this->FarmID) {
            $event->exit = 'no-farm';
            return;
        }

        $dbFarm = \DBFarm::LoadByID($this->FarmID);
        $servers = $dbFarm->GetServersByFilter(array('status' => array(\SERVER_STATUS::RUNNING)));
        try {
            $DBFarmRole = $event->DBServer->GetFarmRoleObject();
        }
        catch(\Exception $e) {}

        try {
            $DBRole = $event->DBServer->GetFarmRoleObject()->GetRoleObject();
        }
        catch(\Exception $e){}

        //HUGE BUG HERE!

        $first_in_role_handled = false;
        $first_in_role_server = null;

        $event->msgExpected = count($servers);

        foreach ($servers as $DBServer) {
            if (!($DBServer instanceof \DBServer))
                continue;

            $isfirstinrole = '0';

            $eventServerIsMaster = $event->DBServer->GetProperty(\SERVER_PROPERTIES::DB_MYSQL_MASTER) || $event->DBServer->GetProperty(\Scalr_Db_Msr::REPLICATION_MASTER);

            if ($eventServerIsMaster && !$first_in_role_handled) {
                if ($DBServer->farmRoleId == $event->DBServer->farmRoleId) {
                    if ($DBServer->GetFarmRoleObject()->GetRoleObject()->hasBehavior(\ROLE_BEHAVIORS::MYSQL) ||
                        $DBServer->GetFarmRoleObject()->GetRoleObject()->getDbMsrBehavior())
                    {
                        $first_in_role_handled = true;
                        $first_in_role_server = $DBServer;
                        $isfirstinrole = '1';
                    }
                }
            }

            $msg = new \Scalr_Messaging_Msg_HostDown();

            //TODO:

            $msg->behaviour = ($DBRole) ? $DBRole->getBehaviors() : '*Unknown*';
            $msg->roleName = ($DBRole) ? $DBRole->name : '*Unknown*';
            $msg->localIp = $event->DBServer->localIp;
            $msg->remoteIp = $event->DBServer->remoteIp;

            $msg->isFirstInRole = $isfirstinrole;
            $msg->serverIndex = $event->DBServer->index;
            $msg->farmRoleId = $event->DBServer->farmRoleId;
            $msg->serverId = $event->DBServer->serverId;
            $msg->cloudLocation = $event->DBServer->GetCloudLocation();

            // If FarmRole was removed from farm, no configuration left
            if ($DBFarmRole) {
                $msg =\Scalr_Scripting_Manager::extendMessage($msg, $event, $event->DBServer, $DBServer);

                if ($event->DBServer->farmRoleId != 0) {
                    foreach (\Scalr_Role_Behavior::getListForRole($event->DBServer->GetFarmRoleObject()->GetRoleObject()) as $behavior)
                        $msg = $behavior->extendMessage($msg, $event->DBServer);
                }
            }

            $msg = $DBServer->SendMessage($msg, false, true);
            if ($msg)
                $event->msgCreated++;

            $loopServerIsMaster =  $DBServer->GetProperty(\SERVER_PROPERTIES::DB_MYSQL_MASTER) || $DBServer->GetProperty(\Scalr_Db_Msr::REPLICATION_MASTER);
            if ($loopServerIsMaster && $DBServer->status ==\SERVER_STATUS::RUNNING) {
                $doNotPromoteSlave2Master = true;
            }

        }

        if (!$DBFarmRole)
            return;


        if ($DBFarmRole->GetRoleObject()->getDbMsrBehavior() && !$doNotPromoteSlave2Master && !$DBFarmRole->GetSetting(\Scalr_Db_Msr::SLAVE_TO_MASTER))
            $this->sendPromoteToMasterMessage($event);

        //LEGACY MYSQL CODE:
        if ($DBFarmRole->GetRoleObject()->hasBehavior(\ROLE_BEHAVIORS::MYSQL)) {
            // If EC2 master down
            if (($event->DBServer->GetProperty(\SERVER_PROPERTIES::DB_MYSQL_MASTER)) &&
                $DBFarmRole)
            {
                $master = $dbFarm->GetMySQLInstances(true);
                if($master[0])
                    return;

                $msg = new \Scalr_Messaging_Msg_Mysql_PromoteToMaster(
                    $DBFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_ROOT_PASSWORD),
                    $DBFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_REPL_PASSWORD),
                    $DBFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_STAT_PASSWORD)
                );

                if ($event->DBServer->IsSupported("0.7"))
                {
                    if (in_array($event->DBServer->platform, array(\SERVER_PLATFORMS::EC2,\SERVER_PLATFORMS::CLOUDSTACK,\SERVER_PLATFORMS::IDCF))) {
                        try {
                            $volume =\Scalr_Storage_Volume::init()->loadById(
                                $DBFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_SCALR_VOLUME_ID)
                            );

                            $msg->volumeConfig = $volume->getConfig();
                        } catch (\Exception $e) {
                            $this->Logger->error(new \FarmLogMessage(
                                $event->DBServer->farmId,
                                "Cannot create volumeConfig for PromoteToMaster message: {$e->getMessage()}",
                                !empty($event->DBServer->serverId) ? $event->DBServer->serverId : null
                            ));
                        }
                    }
                }
                elseif ($event->DBServer->platform ==\SERVER_PLATFORMS::EC2)
                    $msg->volumeId = $DBFarmRole->GetSetting(Entity\FarmRoleSetting::MYSQL_MASTER_EBS_VOLUME_ID);

                // Send Mysql_PromoteToMaster to the first server in the same avail zone as old master (if exists)
                // Otherwise send to first in role
                $platform = $event->DBServer->platform;
                if ($platform ==\SERVER_PLATFORMS::EC2) {
                    $availZone = $event->DBServer->GetProperty(\EC2_SERVER_PROPERTIES::AVAIL_ZONE);
                }

                foreach ($servers as $DBServer) {

                    if ($DBServer->serverId == $event->DBServer->serverId)
                        continue;

                    if (($platform ==\SERVER_PLATFORMS::EC2 && $DBServer->GetProperty(\EC2_SERVER_PROPERTIES::AVAIL_ZONE) == $availZone) || $platform !=\SERVER_PLATFORMS::EC2) {
                        if ($DBServer->GetFarmRoleObject()->GetRoleObject()->hasBehavior(\ROLE_BEHAVIORS::MYSQL)) {
                            $DBFarmRole->SetSetting(Entity\FarmRoleSetting::MYSQL_SLAVE_TO_MASTER, 1);
                            $DBServer->SetProperty(\SERVER_PROPERTIES::DB_MYSQL_MASTER, 1);
                            $DBServer->SendMessage($msg, false, true);
                            return;
                        }
                    }
                }

                if ($first_in_role_server) {
                    $DBFarmRole->SetSetting(Entity\FarmRoleSetting::MYSQL_SLAVE_TO_MASTER, 1);
                    $first_in_role_server->SetProperty(SERVER_PROPERTIES::DB_MYSQL_MASTER, 1);
                    $first_in_role_server->SendMessage($msg, false, true);
                }
            }
        }
    }
}
