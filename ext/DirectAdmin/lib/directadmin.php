<?php
/**
* GlobalSign OneclickSSL extention for DirectAdmin
*
* Replacing the slow and error prone process of CSR creation, key management,
* approver emails and Certificate installation with a single click!
*
* PHP version 5
*
* LICENSE: BSD License
*
* Copyright � 2012 GMO GlobalsSign KK.
* All Rights Reserved.
*
* Redistribution and use in source and binary forms, with or without
* modification, are permitted provided that the following conditions
* are met:
* 1. Redistributions of source code must retain the above copyright
*    notice, this list of conditions and the following disclaimer.
* 2. Redistributions in binary form must reproduce the above copyright
*    notice, this list of conditions and the following disclaimer in the
*    documentation and/or other materials provided with the distribution.
* 3. The name of the author may not be used to endorse or promote products
*    derived from this software without specific prior written permission.
*
* THIS SOFTWARE IS PROVIDED BY GMO GLOBALSIGN KK "AS IS" AND ANY EXPRESS OR
* IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES
* OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
* IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT,
* INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT
* NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
* DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
* THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
* (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF
* THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*
* @copyright  Copyright � 2012 GMO GlobalsSign KK. All Rights Reserved. (http://www.globalsign.com)
* @license    BSD License (3 Clause)
* @version    $Id$
* @link       http://www.globalsign.com/ssl/oneclickssl/
*/

/**
 * Read plugin config file
 */
$config = $_ENV['DOCUMENT_ROOT'] .'/../etc/oneclick.conf';
$settings = unserialize(@file_get_contents($config));

if ($_SERVER['SSL']) {
    define("DASERVER", "https://". $_SERVER['SERVER_ADDR'] .":". $_SERVER['SERVER_PORT']);
} else {
    define("DASERVER", "http://". $_SERVER['SERVER_ADDR'] .":". $_SERVER['SERVER_PORT']);
}

define("DAUSERNAME", $settings['da_user'] ."|". $_ENV['USERNAME']);
define("DAPASSWORD", $settings['da_passwd']);

define("PLATFORMID", "863251316500001");
define("KEYALGORITHM", "RSA");

require $_ENV['DOCUMENT_ROOT'] . '/../lib/OneClickSSL.php';

class DAOneClick implements OneClickSSLPlugin
{
    protected $_output;

    protected $_domain;

    public $backup = array();

    /**
     * Set the domain for the certificate
     *
     * @param string $domain  The domain for the certificate
     *
     * @return null
     */
    public function setDomain($domain)
    {
        $this->_domain = $domain;
    }

    /**
     * Install the certificate
     */
    public function install($privateKey, $certificate, $cacert = null)
    {
        $this->debug(1, "Preparing DirectAdmin certificate installation for ". $this->_domain);

        // Just some debugging information
        $this->debug(2, "Certificate:\n". $certificate);
        $this->debug(2, "Intermediates:\n". $cacert);

        // Export the certificates to the filesystem
        openssl_pkey_export($privateKey,$privkeySting);
        openssl_x509_export($certificate,$certString);
        if (strlen($cacert) > 10) {
            openssl_x509_export($cacert,$cacertString);
        }

        // Update the private key and the certificate for this website
        $qstr = array();
        $qstr['domain'] = $this->_domain;
        $qstr['action'] = 'save';
        $qstr['type'] = 'paste';
        $qstr['certificate'] = $privkeySting ."\n". $certString;

        // Include the CA certificate
        if (strlen($cacertString) > 10) {
            $qstr['active'] = 'yes';
            $qstr['cacert'] = $cacertString;
        } else {
            $qstr['active'] = 'no';
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, DASERVER .'/CMD_API_SSL');
        curl_setopt($ch, CURLOPT_USERPWD, DAUSERNAME .':'. DAPASSWORD);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($qstr));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $result = curl_exec($ch);
        $resultStatus = curl_getinfo($ch);

        // Did Curl returned an error?
        if(curl_errno($ch)) {
            $this->debug(1, "Error in communication with DirectAdmin (".  curl_error($ch) ."), is the plugin correctly configured?");
            return false;
        }
        curl_close($ch);

        if ($resultStatus['content_type'] == 'text/plain') {
            parse_str($result, $reponse);
            $this->debug(1, $reponse['text']);

            if ($reponse['error'] <> 0) {
                return false;
            }
        } else {
            $this->debug(1, "Unkown error from DirectAdmin while installing certificate");
            $this->debug(3, $result);
            return false;
        }

        return $certString;
    }

    /**
     * Check IP requirements, assign a unique IP if availible
     * - http://www.directadmin.com/features.php?id=1309
     */
    public function checkIp() {
        global $usrSettings;

        // Check DirectAdmin IP settings for this domain
        $qstr = array();
        $qstr['domain'] = $this->_domain;
        $qstr['action'] = 'view';
        $qstr['ips'] = 'yes';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, DASERVER .'/CMD_API_ADDITIONAL_DOMAINS');
        curl_setopt($ch, CURLOPT_USERPWD, DAUSERNAME .':'. DAPASSWORD);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($qstr));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $result = curl_exec($ch);
        $resultStatus = curl_getinfo($ch);

        // Did Curl returned an error?
        if(curl_errno($ch)) {
            $this->debug(1, "Error in communication with DirectAdmin (".  curl_error($ch) ."), is the plugin correctly configured?");
            return false;
        }
        curl_close($ch);

        if ($resultStatus['content_type'] == 'text/plain') {
            parse_str($result, $reponse);
            $this->debug(1, $reponse['text']);

            if ($reponse['error'] <> 0) {
                return false;
            }

            $currentIp = $reponse['ip'];
            parse_str($reponse['assigned_ips'], $assigned_ips);
            //parse_str($reponse['available_ips'], $available_ips);
            parse_str($reponse['shared_ips'], $shared_ips);
            parse_str($reponse['owned_ips'], $owned_ips);

            // Check if we have a owned (dedicated) ip
            $shared = true;
            reset($assigned_ips);
            while (list(, $ip) = each($assigned_ips)) {
                // Not in shared means dedicated
                if (!in_array($ip, $shared_ips)) {
                    $shared = false;
                }

                // Delete active ip's from the owned ip that we have availible
                if (in_array($ip, $owned_ips)) {
                    unset($owned_ips[array_search($ip, $owned_ips)]);
                }
            }

            // Check if we are on a shared ip
            if ($shared) {
                $this->debug(1, "You can only add a certificate if you own the ip you are using, ". $reponse['ip'] ." is used by other sites too.");

                // Can we assign a dedicated ip?
                if (count($owned_ips) > 0 && $usrSettings['auto_ip'] === 1) {
                    reset($owned_ips);
                    $newIp = current($owned_ips);
                    $this->debug(1, "We are goging to assign ip ". $newIp ." to this website.");

                    $qstr = array();
                    $qstr['domain'] = $this->_domain;
                    $qstr['ip'] = $newIp;
                    $qstr['dns'] = 'yes';
                    $qstr['action'] = 'multi_ip';
                    $qstr['add'] = 'Add IP';

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, DASERVER .'/CMD_API_DOMAIN');
                    curl_setopt($ch, CURLOPT_USERPWD, DAUSERNAME .':'. DAPASSWORD);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($qstr));
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_HEADER, false);
                    $result = curl_exec($ch);
                    $resultStatus = curl_getinfo($ch);

                    // Did Curl returned an error?
                    if(curl_errno($ch)) {
                        $this->debug(1, "Error in communication with DirectAdmin (".  curl_error($ch) ."), is the plugin correctly configured?");
                        return false;
                    }
                    curl_close($ch);

                    if ($resultStatus['content_type'] == 'text/plain') {
                        parse_str($result, $reponse);
                        $this->debug(1, $reponse['text']);

                        if ($reponse['error'] <> 0) {
                            return false;
                        }
                    } else {
                        $this->debug(1, "Unkown error from DirectAdmin assigning ip address.");
                        $this->debug(3, $result);
                        return false;
                    }

                    // Delete shared ip from site and remove DNS
                    //  -- next add shared ip (we don't want the site down) and do NOT add DNS
                    $this->debug(1, "We are goging to remove ip temporary ". $currentIp ." to delete the DNS.");

                    $qstr = array();
                    $qstr['domain'] = $this->_domain;
                    $qstr['select0'] = $currentIp;
                    $qstr['removedns'] = 'yes';
                    $qstr['action'] = 'multi_ip';
                    $qstr['delete'] = 'delete';

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, DASERVER .'/CMD_API_DOMAIN');
                    curl_setopt($ch, CURLOPT_USERPWD, DAUSERNAME .':'. DAPASSWORD);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($qstr));
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_HEADER, false);
                    $result = curl_exec($ch);
                    $resultStatus = curl_getinfo($ch);

                    // Did Curl returned an error?
                    if(curl_errno($ch)) {
                        $this->debug(1, "Error in communication with DirectAdmin (".  curl_error($ch) ."), is the plugin correctly configured?");
                        return false;
                    }
                    curl_close($ch);

                    if ($resultStatus['content_type'] == 'text/plain') {
                        parse_str($result, $reponse);
                        $this->debug(1, $reponse['text']);

                        if ($reponse['error'] <> 0) {
                            return false;
                        }
                    } else {
                        $this->debug(1, "Unkown error from DirectAdmin during temporary remove of ip address.");
                        $this->debug(3, $result);
                        return false;
                    }

                    // Add shared (current) ip back, do not add it to the DNS!
                    $this->debug(1, "We are assigning ip ". $currentIp ." back to this website and skip the DNS configuration.");

                    $qstr = array();
                    $qstr['domain'] = $this->_domain;
                    $qstr['ip'] = $currentIp;
                    $qstr['dns'] = 'no';
                    $qstr['action'] = 'multi_ip';
                    $qstr['add'] = 'Add IP';

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, DASERVER .'/CMD_API_DOMAIN');
                    curl_setopt($ch, CURLOPT_USERPWD, DAUSERNAME .':'. DAPASSWORD);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($qstr));
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_HEADER, false);
                    $result = curl_exec($ch);
                    $resultStatus = curl_getinfo($ch);

                    // Did Curl returned an error?
                    if(curl_errno($ch)) {
                        $this->debug(1, "Error in communication with DirectAdmin (".  curl_error($ch) ."), is the plugin correctly configured?");
                        return false;
                    }
                    curl_close($ch);

                    if ($resultStatus['content_type'] == 'text/plain') {
                        parse_str($result, $reponse);
                        $this->debug(1, $reponse['text']);

                        if ($reponse['error'] <> 0) {
                            return false;
                        }
                    } else {
                        $this->debug(1, "Unkown error from DirectAdmin when assigning sahred ip address with no DNS.");
                        $this->debug(3, $result);
                        return false;
                    }

                    // Check if the DNS has been update
                    $this->debug(1, "Verifing if the DNS has been updated to the new assigned ip address ". $newIp);

                    $continue = false;
                    $certStatus = false; $i = 1;
                    while (!$continue) {
                        // ipv4 only
                        //$dnsIp = gethostbynamel($this->_domain);

                        $dnsIp = array();
                        $dns = dns_get_record($this->_domain, DNS_A + DNS_AAAA);

                        // Get all ip in a single array like gethostbynamel, now including ipv6
                        reset($dns);
                        while (list(, $val) = each($dns)) {
                            if (array_key_exists('ip', $val)) {
                                $dnsIp[] = $val['ip'];
                            }
                        }

                        $this->debug(1, "The local DNS gives us : ". implode(', ', $dnsIp ));
                        if (in_array($newIp, $dnsIp)) {
                            $this->debug(1, "DNS is updated and now serving the new ip address");
                            $continue = true;

                        } elseif ($i >= 15) {
                            $this->debug(1, "Sorry the local DNS is not updated, please try again later.");
                            $continue = true;
                            return false;

                        } else {
                            $this->debug(1, "Waiting till the DNS is serving the updated ip address (". $i .")");
                            $this->updateStatus();

                            $i++;
                            sleep(5);
                        }
                    }

                    return true;

                } elseif (count($owned_ips) > 0) {
                    reset($owned_ips);
                    $newIp = current($owned_ips);
                    $this->debug(1, "We could assign ip ". $newIp ." to this website, but this feature has been disabled for your account.");

                } else {
                    $this->debug(1, "We have no unused ip addresses availible that we can assign to this webiste.");
                    $this->debug(1, "Please ask the administrator to assign a new ip address to your account.");

                    // Do we want to contineu if the server supports SNI?
                    return false;
                }
            } else {
                // Nothing to do
                $this->debug(1, "This website is already configured to serve from an unique ip address.");
                return true;
            }

        } else {
            $this->debug(1, "Unkown error from DirectAdmin while installing certificate");
            $this->debug(3, $result);
            return false;
        }
    }

    /**
     * Back the current certificates
     */
    public function backup()
    {
        // Get the current certificates
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, DASERVER .'/CMD_API_SSL?domain='. $this->_domain);
        curl_setopt($ch, CURLOPT_USERPWD, DAUSERNAME .':'. DAPASSWORD);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $result = curl_exec($ch);
        $resultStatus = curl_getinfo($ch);

        // Did Curl returned an error?
        if(curl_errno($ch)) {
            echo "Error in communication with DirectAdmin (".  curl_error($ch) ."), is the plugin correctly configured?";
            return false;
        }
        curl_close($ch);

        if ($resultStatus['content_type'] == 'text/plain') {
            parse_str($result, $reponse);
            $this->debug(1, $reponse['text']);

            if ($reponse['error'] <> 0) {
                return false;
            }
        } else {
            $this->debug(1, "Unkown error from DirectAdmin when creating backup.");
            $this->debug(3, $result);
            return false;
        }

        // Save the current certificates in memory
        $this->backup = $reponse;

        // Include the CA certificate in the backup
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, DASERVER .'/CMD_API_SSL?view=cacert&domain='. $this->_domain);
        curl_setopt($ch, CURLOPT_USERPWD, DAUSERNAME .':'. DAPASSWORD);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $result = curl_exec($ch);
        $resultStatus = curl_getinfo($ch);

        // Did Curl returned an error?
        if(curl_errno($ch)) {
            echo "Error in communication with DirectAdmin (".  curl_error($ch) ."), is the plugin correctly configured?";
            return false;
        }
        curl_close($ch);

        if ($resultStatus['content_type'] == 'text/plain') {
            parse_str($result, $reponse);
            $this->debug(1, $reponse['text']);

            if ($reponse['error'] <> 0) {
                return false;
            }
        } else {
            $this->debug(1, "Unkown error from DirectAdmin when backupping CA certificate.");
            $this->debug(3, $result);
            return false;
        }

        // Include the CA certificate
        $this->backup['cacert'] = $reponse['cacert'];
        $this->backup['active'] = $reponse['enabled'];

        return true;
    }

    /**
     * Restore the backup certificates
     */
    public function restoreBackup()
    {
        // Get the backup from memory
        $backup = $this->backup;
        $backup['domain'] = $this->_domain;
        $backup['action'] = 'save';
        $backup['type'] = 'paste';
        $backup['certificate'] = $backup['key'] ."\n". $backup['certificate'];

        unset($backup['key']);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, DASERVER .'/CMD_API_SSL');
        curl_setopt($ch, CURLOPT_USERPWD, DAUSERNAME .':'. DAPASSWORD);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($backup));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $result = curl_exec($ch);
        $resultStatus = curl_getinfo($ch);

        // Did Curl returned an error?
        if(curl_errno($ch)) {
            echo "Error in communication with DirectAdmin (".  curl_error($ch) ."), is the plugin correctly configured?";
            return false;
        }
        curl_close($ch);

        if ($resultStatus['content_type'] == 'text/plain') {
            parse_str($result, $reponse);
            $this->debug(1, $reponse['text']);

            if ($reponse['error'] <> 0) {
                return false;
            }
        } else {
            $this->debug(1, "Unkown error from DirectAdmin when restoring backup.");
            $this->debug(3, $result);
            return false;
        }

        // Save the current certificates in memory
        unset($this->backup);
        return true;
    }

    /**
     * Set the output handler object
     *
     * @param Output_Output $output  Output handler object
     *
     * @return DAOneClick
     */
    public function setOutput(Output_Output $output)
    {
        $this->_output = $output;
        return $this;
    }

    /**
     * Write a message to the debugger
     *
     * @param int    $level    Level of message to send to debug
     * @param string $message  Message to send
     *
     * @return null
     */
    protected function debug($level, $message)
    {
        $this->_output->debug()->write($level, $message);
    }

    /**
     * Update the status file, if enabled
     *
     * @return null
     */
    protected function updateStatus()
    {
        try {
            if (!$this->output()->status()->updateStatus($this->_certData->getDomain())) {
                $this->debug(3, 'Skip writing status information to file');
            }
        } catch (RunTimeException $e) {
            $this->debug(1, $e->getMessage());
        }
    }
}