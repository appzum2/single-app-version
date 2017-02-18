<?php

/**
 * Class Backoffice_Advanced_ConfigurationController
 */
class Backoffice_Advanced_ConfigurationController extends System_Controller_Backoffice_Default {

    /**
     * @var array
     */
    public $_codes  = array(
        "disable_cron",
        "cron_interval",
        "environment",
        "update_channel",
        "use_https",
        "cpanel_type",
        "letsencrypt_env",
        "send_statistics",
    );

    public $_fake_password_key = "__not_safe_not_saved__";

    public function loadAction() {

        $html = array(
            "title" => __("Advanced")." > ".__("Configuration"),
            "icon" => "fa-toggle-on",
        );

        $this->_sendHtml($html);
    }

    public function findallAction() {

        $data = $this->_findconfig();

        $cpanel = Api_Model_Key::findKeysFor("cpanel");
        $plesk = Api_Model_Key::findKeysFor("plesk");
        $vestacp = Api_Model_Key::findKeysFor("vestacp");
        $directadmin = Api_Model_Key::findKeysFor("directadmin");

        $data["cpanel"] = $cpanel->getData();
        $data["plesk"] = $plesk->getData();
        $data["vestacp"] = $vestacp->getData();
        $data["directadmin"] = $directadmin->getData();


        $data["cpanel"]["password"]         = $this->_fake_password_key;
        $data["plesk"]["password"]          = $this->_fake_password_key;
        $data["vestacp"]["password"]        = $this->_fake_password_key;
        $data["directadmin"]["password"]    = $this->_fake_password_key;

        $ssl_certificate_model = new System_Model_SslCertificates();
        $certs = $ssl_certificate_model->findAll();

        $is_pe = Siberian_Version::is("PE");
        if($is_pe) {
            $whitelabel_model = new Whitelabel_Model_Editor();
        }

        $data["certificates"] = array();
        foreach($certs as $cert) {

            $wls = array();
            if($is_pe) {
                $whitelabels = $whitelabel_model->findAll(array("is_active = ?", "1"));
                foreach($whitelabels as $whitelabel) {
                    $wls[] = $whitelabel->getHost();
                }
            }

            $data["certificates"][] = array(
                "id" => $cert->getId(),
                "whitelabels" => $wls,
                "domains" => Siberian_Json::decode($cert->getDomains()),
                "hostname" => $cert->getHostname(),
                "certificate" => $cert->getCertificate(),
                "chain" => $cert->getChain(),
                "fullchain" => $cert->getFullchain(),
                "last" => $cert->getLast(),
                "private" => $cert->getPrivate(),
                "public" => $cert->getPublic(),
                "source" => __($cert->getSource()),
                "created_at" => $cert->getFormattedCreatedAt(),
                "updated_at" => $cert->getFormattedUpdatedAt(),
                "show_info" => false,
                "more_info" => __("-"),
            );
        }

        $this->_sendHtml($data);

    }

    /**
     * Save settings
     * &
     * cpanel/plesk credentials
     */
    public function saveAction() {

        if($data = Zend_Json::decode($this->getRequest()->getRawBody())) {

            try {
                $this->_save($data);

                $message = __("Configuration successfully saved");

                if(isset($data["environment"]) && in_array($data["environment"]["value"], array("production", "development"))) {
                    $config_file = Core_Model_Directory::getBasePathTo("config.php");
                    if(is_writable($config_file)) {
                        $contents = file_get_contents($config_file);
                        $contents = preg_replace('/"(development|production)"/im', '"'.$data["environment"]["value"].'"', $contents);
                        file_put_contents($config_file, $contents);
                    } else {
                        $message = __("Configuration partially saved")."<br />".__("Error: unable to write Environment in config.php");
                    }
                }

                //Admin panel type & credentials
                $api_provider = new Api_Model_Provider();
                $api_key = new Api_Model_Key();

                $panel_type = $data["cpanel_type"]["value"];
                $panel_api_provider = $api_provider->find($panel_type, "code");
                if($panel_api_provider->getId()) {
                    $keys = $api_key->findAll(array("provider_id = ?" => $panel_api_provider->getId()));
                    foreach($keys as $key) {
                        switch($key->getKey()) {
                            case "host":
                                $key->setValue($data[$panel_type]["host"])->save();
                                break;
                            case "user":
                                $key->setValue($data[$panel_type]["user"])->save();
                                break;
                            case "password":
                                if($data[$panel_type]["password"] != $this->_fake_password_key) {
                                    $key->setValue($data[$panel_type]["password"])->save();
                                }
                                break;
                        }
                    }
                }

                $data = array(
                    "success" => 1,
                    "message" => $message,
                );
            } catch(Exception $e) {
                $data = array(
                    "error" => 1,
                    "message" => $e->getMessage(),
                );
            }

            $this->_sendHtml($data);

        }

    }

    /**
     * Delete a certificate
     */
    public function removecertAction() {
        if($cert_id = $this->getRequest()->getParam("cert_id")) {
            try {

                $ssl_certificate_model = new System_Model_SslCertificates();

                $cert = $ssl_certificate_model->find($cert_id);
                $cert->delete();

                $certs = $ssl_certificate_model->findAll();

                $data = array(
                    "success" => 1,
                    "message" => __("Successfully removed the certificate."),
                );

                $data["certificates"] = array();
                foreach($certs as $cert) {
                    $data["certificates"][] = array(
                        "id" => $cert->getId(),
                        "hostname" => $cert->getHostname(),
                        "certificate" => $cert->getCertificate(),
                        "chain" => $cert->getChain(),
                        "fullchain" => $cert->getFullchain(),
                        "last" => $cert->getLast(),
                        "private" => $cert->getPrivate(),
                        "public" => $cert->getPublic(),
                        "source" => __($cert->getSource()),
                        "created_at" => $cert->getFormattedCreatedAt(),
                        "updated_at" => $cert->getFormattedUpdatedAt(),
                        "show_info" => false,
                        "more_info" => __("-"),
                    );
                }

            } catch(Exception $e) {
            $data = array(
                "error" => 1,
                "message" => $e->getMessage(),
            );
        }

            $this->_sendHtml($data);
        }
    }

    public function createcertificateAction() {

        if($data = Zend_Json::decode($this->getRequest()->getRawBody())) {

            try {
                if(empty($data["hostname"]) || empty($data["cert_path"]) || empty($data["private_path"])) {
                    throw new Exception("#824-01: ".__("All the files are required."));
                }

                $base = Core_Model_Directory::getBasePathTo("/var/apps/certificates/");

                # Test hostname
                $current_host = $this->getRequest()->getHttpHost();
                $current_ip = gethostbyname($current_host);
                $ip = gethostbyname($data["hostname"]);
                $letsencrypt_env = System_Model_Config::getValueFor("letsencrypt_env");

                if($current_ip != $ip) {
                    throw new Exception("#824-02: ".__("The domain %s doesn't belong to you, or is not configured on your server.", $data["hostname"]));
                }

                $ssl_certificate_model = new System_Model_SslCertificates();
                $ssl_cert = $ssl_certificate_model->find($data["hostname"], "hostname");

                if($ssl_cert->getId()) {
                    throw new Exception("#824-03: ".__("An entry already exists for this hostname, remove it first if you need to change your certificates."));
                }

                if($data["upload"] == "1") {
                    # Move the TMP files, then save the certificate
                    $folder = $base."/".$data["hostname"];
                    if(!file_exists($folder)) {
                        mkdir($folder, 0777, true);
                    }

                    rename($data["cert_path"], $folder."/cert.pem");
                    rename($data["private_path"], $folder."/private.pem");

                    # Read SAN
                    $cert_content = openssl_x509_parse(file_get_contents($folder."/cert.pem"));
                    if(isset($cert_content["extensions"]) && $cert_content["extensions"]["subjectAltName"]) {
                        $parts = explode(",", str_replace("DNS:", "", $cert_content["extensions"]["subjectAltName"]));
                        $certificate_hosts = array();
                        foreach($parts as $part) {
                            $certificate_hosts[] = trim($part);
                        }
                    } else {
                        $certificate_hosts = array($data["hostname"]);
                    }

                    if(empty($certificate_hosts)) {
                        $certificate_hosts = array($data["hostname"]);
                    }

                    $ssl_cert
                        ->setHostname($data["hostname"])
                        ->setCertificate($folder."/cert.pem")
                        ->setPrivate($folder."/private.pem")
                        ->setDomains(Siberian_Json::encode($certificate_hosts))
                        ->setEnvironment($letsencrypt_env)
                        ->setSource(System_Model_SslCertificates::SOURCE_CUSTOMER)
                    ;

                    if(is_readable($data["ca_path"])) {
                        rename($data["ca_path"], $folder."/ca.pem");
                        $ssl_cert->setChain($folder."/ca.pem");
                    }

                    $ssl_cert->save();

                } else {
                    # Test if all three paths are readable from Siberian !!!
                    if(!is_readable($data["cert_path"]) || !is_readable($data["private_path"])) {
                        throw new Exception("#824-06: ".__("One of the three given Certificates path is not readable, please make sure they have the good rights."));
                    }

                    # Read SAN
                    $cert_content = openssl_x509_parse(file_get_contents($data["cert_path"]));
                    if(isset($cert_content["extensions"]) && $cert_content["extensions"]["subjectAltName"]) {
                        $parts = explode(",", str_replace("DNS:", "", $cert_content["extensions"]["subjectAltName"]));
                        $certificate_hosts = array();
                        foreach($parts as $part) {
                            $certificate_hosts[] = trim($part);
                        }
                    } else {
                        $certificate_hosts = array($data["hostname"]);
                    }

                    if(empty($certificate_hosts)) {
                        $certificate_hosts = array($data["hostname"]);
                    }

                    # Save the path as-is
                    $ssl_cert
                        ->setHostname($data["hostname"])
                        ->setCertificate($data["cert_path"])
                        ->setChain($data["ca_path"])
                        ->setPrivate($data["private_path"])
                        ->setDomains(Siberian_Json::encode($certificate_hosts))
                        ->setSource(System_Model_SslCertificates::SOURCE_CUSTOMER)
                        ->setEnvironment($letsencrypt_env)
                        ->save()
                    ;
                }

                // SocketIO
                if(class_exists("SocketIO_Model_SocketIO_Module") && method_exists("SocketIO_Model_SocketIO_Module", "killServer")) {
                    SocketIO_Model_SocketIO_Module::killServer();
                }

                $certs = $ssl_certificate_model->findAll();

                $data = array(
                    "success" => 1,
                    "message" => __("Your certificates are saved."),
                );

                $data["certificates"] = array();
                foreach($certs as $cert) {
                    $data["certificates"][] = array(
                        "id" => $cert->getId(),
                        "hostname" => $cert->getHostname(),
                        "certificate" => $cert->getCertificate(),
                        "chain" => $cert->getChain(),
                        "fullchain" => $cert->getFullchain(),
                        "last" => $cert->getLast(),
                        "private" => $cert->getPrivate(),
                        "public" => $cert->getPublic(),
                        "source" => __($cert->getSource()),
                        "created_at" => $cert->getFormattedCreatedAt(),
                        "updated_at" => $cert->getFormattedUpdatedAt(),
                        "show_info" => false,
                        "more_info" => __("-"),
                    );
                }

            } catch(Exception $e) {
                $data = array(
                    "error" => 1,
                    "message" => $e->getMessage(),
                );
            }

            $this->_sendHtml($data);

        }

    }

    /**
     * Simple action to check if host is ok (with json response)
     */
    public function checkhttpAction() {
        $data = array(
            "success" => 1,
            "message" => __("Success"),
        );

        $this->_sendHtml($data);
    }

    /**
     * Simple action to check if host is ok (with json response)
     */
    public function checksslAction() {
        $http_host = $this->getRequest()->getHttpHost();
        $request = new Siberian_Request();
        $result = $request->get("https://".$http_host);
        if($result) {
            $data = array(
                "success" => 1,
                "message" => __("Success"),
                "https_url" => "https://".$http_host,
                "http_url" => "http://".$http_host,
            );
        } else {
            $data = array(
                "error" => 1,
                "message" => __("HTTPS not reachable"),
                "https_url" => "https://".$http_host,
                "http_url" => "http://".$http_host,
            );
        }

        $this->_sendHtml($data);
    }

    /**
     * Yes ....
     */
    public function clearpleskAction() {
        try {
            $logger = Zend_Registry::get("logger");
            $hostname = $this->getRequest()->getHttpHost();
            $ssl_certificate_model = new System_Model_SslCertificates();
            $cert = $ssl_certificate_model->find($hostname, "hostname");

            $siberian_plesk = new Siberian_Plesk();
            $siberian_plesk->removeCertificate($cert);

            $data = array(
                "success" => 1,
                "message" => "#824-56: ".__("Successfully cleaned-up old certificate."),
            );
        } catch(Exception $e) {
            $logger->info("[clearpleskAction]: An error occured %s", $e->getMessage());

            $data = array(
                "error" => 1,
                "message" => "#824-55: ".__("[Plesk] %s", $e->getMessage()),
            );
        }

        $this->_sendHtml($data);
    }

    /**
     * Yes ....
     */
    public function installpleskAction() {
        try {
            $logger = Zend_Registry::get("logger");
            $hostname = $this->getRequest()->getHttpHost();
            $ssl_certificate_model = new System_Model_SslCertificates();
            $cert = $ssl_certificate_model->find($hostname, "hostname");

            $siberian_plesk = new Siberian_Plesk();
            $siberian_plesk->updateCertificate($cert);

            $data = array(
                "success" => 1,
                "message" => "#824-56".__("Successfully installed new certificate."),
            );
        } catch(Exception $e) {
            $logger->info("[clearpleskAction]: An error occured %s", $e->getMessage());

            $data = array(
                "error" => 1,
                "message" => "#824-59: ".__("[Plesk] %s", $e->getMessage()),
            );
        }

        $this->_sendHtml($data);
    }

    /**
     * This action may end unexpectedly because of the panel reloading the webserver
     * This is normal behavior
     */
    public function sendtopanelAction() {
        $logger = Zend_Registry::get("logger");
        $panel_type = System_Model_Config::getValueFor("cpanel_type");
        $hostname = $this->getRequest()->getHttpHost();
        $ssl_certificate_model = new System_Model_SslCertificates();
        $cert = $ssl_certificate_model->find($hostname, "hostname");

        $ui_panels = array(
            "plesk" => __("Plesk"),
            "cpanel" => __("WHM cPanel"),
            "vestacp" => __("VestaCP"),
            "directadmin" => __("DirectAdmin"),
            "self" => __("Self-managed"),
        );

        // Sync cPanel - Plesk - VestaCP (beta) - DirectAdmin (beta)
        try {
            $message = __("Successfully saved Certificate to %s", $ui_panels["$panel_type"]);
            switch($panel_type) {
                case "plesk":
                        $siberian_plesk = new Siberian_Plesk();
                        $siberian_plesk->selectCertificate($cert);
                    break;
                case "cpanel":
                        $cpanel = new Siberian_Cpanel();
                        $cpanel->updateCertificate($cert);
                    break;
                case "vestacp":
                        $vestacp = new Siberian_VestaCP();
                        $vestacp->updateCertificate($cert);
                    break;
                case "directadmin":
                        $directadmin = new Siberian_DirectAdmin();
                        $directadmin->updateCertificate($cert);
                    break;
                case "self":
                        $logger->info(__("Self-managed sync is not available for now."));
                        $message = __("Self-managed sync is not available for now.");
                    break;
            }

            $data = array(
                "success" => 1,
                "message" => $message,
            );

        } catch(Exception $e) {
            $logger->info("#824-50: ".__("An error occured while saving certificate to %s.", $e->getMessage()));
            $data = array(
                "error" => 1,
                "message" => $e->getMessage(),
            );
        }

        $this->_sendHtml($data);
    }

    /**
     * Generate SSL from Let's Encrypt, then sync Admin Panel
     */
    public function generatesslAction() {
        $ui_panels = array(
            "plesk" => __("Plesk"),
            "cpanel" => __("WHM cPanel"),
            "vestacp" => __("VestaCP"),
            "directadmin" => __("DirectAdmin"),
            "self" => __("Self-managed"),
        );

        $logger = Zend_Registry::get("logger");

        try {
            // Check panel type
            $panel_type = System_Model_Config::getValueFor("cpanel_type");
            if($panel_type == "-1") {
                throw new Exception(__("You must select an Admin panel type before requesting a Certificate."));
            }

            $request = $this->getRequest();
            $email = System_Model_Config::getValueFor("support_email");
            $show_force = false;

            $letsencrypt_env = System_Model_Config::getValueFor("letsencrypt_env");

            $root = Core_Model_Directory::getBasePathTo("/");
            $base = Core_Model_Directory::getBasePathTo("/var/apps/certificates/");
            $hostname = $request->getHttpHost();

            // Build hostnames list
            $hostnames = array($hostname);

            // Adding a fake subdomain www. to the main hostname (mainly for cPanel, VestaCP)
            if(in_array($panel_type, array("cpanel", "plesk", "vestacp", "directadmin"))) {
                $hostnames[] = "www.".$hostname;
            }

            // Add whitelabels if PE
            $is_pe = Siberian_Version::is("PE");
            if($is_pe) {
                $whitelabel_model = new Whitelabel_Model_Editor();
                $whitelabels = $whitelabel_model->findAll(array("is_active = ?", "1"));
                foreach($whitelabels as $whitelabel) {
                    $hostnames[] = $whitelabel->getHost();
                }
            }

            /* @too SSL/HTTPS
             * @todo SKIPPING APP CUSTOM DOMAINS not yet supported
             * @todo redirect .well-known challenges if app/domain
            //
            // Then application domains
            $application_model = new Application_Model_Application();
            $apps = $application_model->findAll(array("domain IS NOT NULL"));

            foreach($apps as $app) {
                $hostnames[] = $app->getDomain();
            }
            */

            // Clean-up empty ones
            // Truncate domains list to 100 (SAN is limited to 100 domains
            $hostnames = array_slice(array_unique(array_filter($hostnames, 'strlen')), 0, 100);

            // Ensure folders have good rights
            exec("chmod -R 775 {$base}");
            exec("chmod -R 777 {$root}/.well-known");

            $lets_encrypt = new Siberian_LetsEncrypt($base, $root, $logger);

            // Use staging environment
            if($letsencrypt_env == "staging") {
                $lets_encrypt->setIsStaging();
            }

            if(!empty($email)) {
                $lets_encrypt->contact = array("mailto:{$email}");
            }

            $ssl_certificate_model = new System_Model_SslCertificates();
            $cert = $ssl_certificate_model->find($request->getHttpHost(), "hostname");

            // Testing given hostnames
            file_put_contents("{$root}/.well-known/check", "1");
            $domains_to_remove = array();
            foreach($hostnames as $_hostname) {
                // Using an external proxy to reach the domain, as sometimes from localhost it's working and shouldn't.
                $proxy01 = "http://proxy01.siberiancms.com/acme-challenge.php";
                $query = array(
                    "secret" => Core_Model_Secret::SECRET,
                    "type" => Siberian_Version::TYPE,
                    "url" => "http://".$_hostname."/.well-known/check",
                );
                $uri = sprintf("%s?%s", $proxy01, http_build_query($query));

                $logger->info(__("Testing: %s", $uri));
                if(file_get_contents($uri) != "1") {
                    $domains_to_remove[] = $_hostname;
                }
            }
            // Removing unreachable hostnames
            $hostnames = array_diff($hostnames, $domains_to_remove);

            $logger->info(__("Removing domains: %s", implode(", ", $domains_to_remove)));

            if(empty($hostnames)) {
                $hostnames = array($request->getHttpHost());
            }

            // Before generating certificate again, compare $hostnames
            $renew = false;
            if(is_readable($cert->getCertificate()) && !empty($hostnames)) {
                $cert_content = openssl_x509_parse(file_get_contents($cert->getCertificate()));
                if(isset($cert_content["extensions"]) && $cert_content["extensions"]["subjectAltName"]) {
                    $certificate_hosts = explode(",", str_replace("DNS:", "", $cert_content["extensions"]["subjectAltName"]));
                    foreach($hostnames as $_hostname) {
                        $_hostname = trim($_hostname);
                        if(!in_array($_hostname, $certificate_hosts)) {
                            $renew = true;
                            $logger->info(__("[Let's Encrypt] will add %s to SAN.", $_hostname));
                        }
                    }
                }
            } else {
                $renew = true;
            }

            // staging against production, renew if environment is different
            if($cert->getEnvironment() != $letsencrypt_env) {
                $renew = true;
            }

            // Whether to renew or not the certificate
            if($renew) {
                $logger->info(__("[Let's Encrypt] renewing certificate."));

                $lets_encrypt->initAccount();
                $result = $lets_encrypt->signDomains($hostnames);
            } else {
                // Sync cert/panel
                $result = true;
            }

            if($result) {

                $cert
                    ->setHostname($hostname)
                    ->setSource(System_Model_SslCertificates::SOURCE_LETSENCRYPT)
                    ->setCertificate(sprintf("%s%s/%s", $base, $hostname, "cert.pem"))
                    ->setChain(sprintf("%s%s/%s", $base, $hostname, "chain.pem"))
                    ->setFullchain(sprintf("%s%s/%s", $base, $hostname, "fullchain.pem"))
                    ->setLast(sprintf("%s%s/%s", $base, $hostname, "last.csr"))
                    ->setPrivate(sprintf("%s%s/%s", $base, $hostname, "private.pem"))
                    ->setPublic(sprintf("%s%s/%s", $base, $hostname, "public.pem"))
                    ->setEnvironment($letsencrypt_env)
                    ->setRenewDate(time_to_date(time() + 10, "YYYY-MM-dd HH:mm:ss"))
                    ->setDomains(Siberian_Json::encode(array_values($hostnames), JSON_OBJECT_AS_ARRAY))
                    ->save();

                // SocketIO
                if(class_exists("SocketIO_Model_SocketIO_Module")) {
                    SocketIO_Model_SocketIO_Module::killServer();
                }

                $message = __("Certificate successfully generated. Please wait while %s is reloading configuration ...", $ui_panels[$panel_type]);

            } else {
                $message = "#824-07: ".__("An unknown error occured while issueing your certificate.");
            }

            // Ensure folders have good rights
            exec("chmod -R 775 {$base}");
            exec("chmod -R 777 {$root}/.well-known");

            $data = array(
                "success" => 1,
                "show_force" => $show_force,
                "message" => $message,
                "all_messages" => array(
                    "https_unreachable" => "#824-99: ".__("HTTPS host is unreachable."),
                    "polling_reload" => __("Waiting for %s to reload, this can take a while...", $panel_type),
                ),
            );

        } catch (Exception $e) {
            $data = array(
                "error" => 1,
                "message" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            );
        }

        $this->_sendHtml($data);
    }

    /**
     *
     */
    public function uploadcertificateAction() {

        try {

            if(empty($_FILES) || empty($_FILES['file']['name'])) {
                throw new Exception(__("No file has been sent"));
            }

            $tmp_dir = Core_Model_Directory::getTmpDirectory(true);
            $adapter = new Zend_File_Transfer_Adapter_Http();
            $adapter->setDestination($tmp_dir);
            $code = $this->getRequest()->getParam("code");

            if($adapter->receive()) {

                $file = $adapter->getFileInfo();

                $uuid = uniqid();
                $new_name = "{$uuid}.pem";
                rename($file["file"]["tmp_name"], $tmp_dir."/".$new_name);

                $data = array(
                    "success" => 1,
                    "code" => $code,
                    "tmp_name" => $file["file"]["name"],
                    "tmp_path" => $tmp_dir."/".$new_name,
                );

            } else {
                $messages = $adapter->getMessages();
                if(!empty($messages)) {
                    $message = implode("\n", $messages);
                } else {
                    $message = __("An error occurred during the process. Please try again later.");
                }

                throw new Exception($message);
            }
        } catch(Exception $e) {
            $data = array(
                "error" => 1,
                "message" => $e->getMessage()
            );
        }

        $this->_sendHtml($data);
    }

    /**
     *
     */
    public function downloadcertAction() {
        $request = $this->getRequest();
        $cert_id = $request->getParam("cert_id");
        $type = $request->getParam("type");

        $ssl_certificate_model = new System_Model_SslCertificates();
        $cert = $ssl_certificate_model->find($cert_id);

        if($cert) {
            switch($type) {
                case "csr":
                    $name = sprintf("%s-%s", $cert->getHostname(), "last.csr");
                    $path = $cert->getLast();
                    break;
                case "cert":
                        $name = sprintf("%s-%s", $cert->getHostname(), "certificate.pem");
                        $path = $cert->getCertificate();
                    break;
                case "ca":
                        $name = sprintf("%s-%s", $cert->getHostname(), "ca.pem");
                        $path = $cert->getChain();
                    break;
                case "private":
                        $name = sprintf("%s-%s", $cert->getHostname(), "private.pem");
                        $path = $cert->getPrivate();
                    break;
            }

            $this->_download($path, $name, "application/octet-stream");
        }
    }

}
