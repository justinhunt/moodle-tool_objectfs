<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Azure Blob Storage client.
 *
 * @package    tool_objectfs
 * @author     Nicholas Hoobin <nicholashoobin@catalyst-au.net>
 * @copyright  Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\local\store\azure;

use admin_setting_description;
use SimpleXMLElement;
use stdClass;
use tool_objectfs\check\token_expiry;
use tool_objectfs\local\store\azure\stream_wrapper;
use tool_objectfs\local\store\object_client_base;

/**
 * client
 */
class client extends object_client_base {

    /** @var BlobRestProxy $client The Blob client. */
    protected $client;

    /** @var string $container The current container. */
    protected $container;

    /**
     * The azure client constructor.
     *
     * @param \stdclass $config
     */
    public function __construct($config) {
        global $CFG;
        $this->autoloader = $CFG->dirroot . '/local/azure_storage/vendor/autoload.php';

        if ($this->get_availability() && !empty($config)) {
            require_once($this->autoloader);
            $this->maxupload = \MicrosoftAzure\Storage\Common\Internal\Resources::MAX_BLOCK_BLOB_SIZE;
            $this->container = $config->azure_container;
            $this->config = $config;
            $this->set_client($config);
        } else {
            parent::__construct($config);
        }
    }

    /**
     * Configures the BlobRestProxy client for access with the SAS token provided.
     *
     * @param stdClass $config
     */
    public function set_client($config) {
        $accountname = $config->azure_accountname;
        $sastoken = $this->clean_sastoken($config->azure_sastoken);

        // If the account name is specified, append a period to create a valid url.
        // When $accountname is not set, prevent the general exception validation error.
        if ($accountname) {
            $accountname .= '.';
        }

        $sasconnectionstring = "BlobEndpoint=https://" .
            $accountname .
            "blob.core.windows.net;SharedAccessSignature=" .
            $sastoken;

        $sasconnectionstring = str_replace(' ', '', $sasconnectionstring);

        $this->client = \MicrosoftAzure\Storage\Common\ServicesBuilder::getInstance()->createBlobService($sasconnectionstring);
    }

    /**
     * Sets the StreamWrapper to allow accessing the remote content via a blob:// path.
     */
    public function register_stream_wrapper() {
        if ($this->get_availability()) {
            stream_wrapper::register($this->client);
        } else {
            parent::register_stream_wrapper();
        }
    }

    /**
     * Returns azure fullpath to use with php file functions.
     *
     * @param  string $contenthash contenthash used as key in azure.
     * @return string fullpath to azure object.
     */
    public function get_fullpath_from_hash($contenthash) {
        $filepath = $this->get_filepath_from_hash($contenthash);
        return "blob://$this->container/$filepath";
    }

    /**
     * Deletes file (blob) in azure blob storage.
     *
     * @param string $fullpath path to azure blob.
     */
    public function delete_file($fullpath) {
        $container = $this->container;
        $relativepath = $this->get_relative_path_from_fullpath($fullpath);

        $this->client->deleteBlob($container, $relativepath);
    }

    /**
     * Moves file (blob) within azure blob storage.
     *
     * @param string $currentpath     current path to file to be moved.
     * @param string $destinationpath destination path to file.
     */
    public function rename_file($currentpath, $destinationpath) {
        copy($currentpath, $destinationpath);

        $this->delete_file($currentpath);
    }

    /**
     * Returns relative path to blob from fullpath to use with php file functions.
     *
     * @param    string    $fullpath full path to azure blob.
     * @return   string    relative path to azure blob.
     */
    public function get_relative_path_from_fullpath($fullpath) {
        $relativepath = str_replace("blob://$this->container/", '', $fullpath);

        return $relativepath;
    }

    /**
     * get_seekable_stream_context
     * @return resource
     */
    public function get_seekable_stream_context() {
        $context = stream_context_create([
            'blob' => [
                'seekable' => true,
            ],
        ]);
        return $context;
    }

    /**
     * Trim a leading '?' character from the sas token.
     *
     * @param string $sastoken
     * @return bool|string
     */
    private function clean_sastoken($sastoken) {
        if (substr($sastoken, 0, 1) === '?') {
            $sastoken = substr($sastoken, 1);
        }

        return $sastoken;
    }

    /**
     * get_md5_from_hash
     * @param string $contenthash
     *
     * @return string
     */
    private function get_md5_from_hash($contenthash) {
        try {
            $key = $this->get_filepath_from_hash($contenthash);

            $result = $this->client->getBlobProperties($this->container, $key)->getProperties();

        } catch (\MicrosoftAzure\Storage\Common\Exceptions\ServiceException $e) {
            return false;
        }

        $contentmd5 = $result->getContentMD5();

        if ($contentmd5) {
            $md5 = bin2hex(base64_decode($contentmd5));
        } else {
            $md5 = trim($result->getETag(), '"'); // Strip quotation marks.
        }

        return $md5;
    }

    /**
     * verify_objectverify_object
     * @param string $contenthash
     * @param string $localpath
     *
     * @return bool
     */
    public function verify_object($contenthash, $localpath) {
        // For objects uploaded to S3 storage using the multipart upload, the etag will not be the objects MD5.
        // So we can't compare here to verify the object.
        // For now we just check that we can retrieve any Etag to verify the object for all supported storages.
        $retrievemd5 = $this->get_md5_from_hash($contenthash);
        if ($retrievemd5) {
            return true;
        }
        return false;
    }

    /**
     * get_filepath_from_hash
     * @param string $contenthash
     *
     * @return string
     */
    protected function get_filepath_from_hash($contenthash) {
        $l1 = $contenthash[0] . $contenthash[1];
        $l2 = $contenthash[2] . $contenthash[3];
        return "$l1/$l2/$contenthash";
    }

    /**
     * test_connection
     * @return stdClass
     */
    public function test_connection() {
        $connection = new \stdClass();
        $connection->success = true;
        $connection->details = '';

        try {
            $this->client->createBlockBlob($this->container, 'connection_check_file', 'connection_check_file');
        } catch (\MicrosoftAzure\Storage\Common\Exceptions\ServiceException $e) {
            $connection->success = false;
            $connection->details = $this->get_exception_details($e);
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            $connection->success = false;
            $connection->details = $e->getMessage();
        }

        return $connection;
    }

    /**
     * test_permissions
     * @param mixed $testdelete
     *
     * @return stdClass
     */
    public function test_permissions($testdelete) {
        $permissions = new \stdClass();
        $permissions->success = true;
        $permissions->messages = [];

        try {
            $result = $this->client->createBlockBlob($this->container, 'permissions_check_file', 'permissions_check_file');
        } catch (\MicrosoftAzure\Storage\Common\Exceptions\ServiceException $e) {
            $details = $this->get_exception_details($e);
            $permissions->messages[get_string('settings:writefailure', 'tool_objectfs') . $details] = 'notifyproblem';
            $permissions->success = false;
        }

        try {
            $result = $this->client->getBlob($this->container, 'permissions_check_file');
        } catch (\MicrosoftAzure\Storage\Common\Exceptions\ServiceException $e) {
            $errorcode = $this->get_body_error_code($e);

            // Write could have failed.
            if ($errorcode !== 'BlobNotFound') {
                $details = $this->get_exception_details($e);
                $permissions->messages[get_string('settings:permissionreadfailure', 'tool_objectfs') . $details] = 'notifyproblem';
                $permissions->success = false;
            }
        }

        try {
            $result = $this->client->deleteBlob($this->container, 'permissions_check_file');
            $permissions->messages[get_string('settings:deletesuccess', 'tool_objectfs')] = 'warning';
            $permissions->success = false;
        } catch (\MicrosoftAzure\Storage\Common\Exceptions\ServiceException $e) {
            $errorcode = $this->get_body_error_code($e);

            // Something else went wrong.
            if ($errorcode !== 'AuthorizationPermissionMismatch') {
                $details = $this->get_exception_details($e);
                $permissions->messages[get_string('settings:deleteerror', 'tool_objectfs') . $details] = 'notifyproblem';
                $permissions->success = false;
            }
        }

        if ($permissions->success) {
            $permissions->messages[get_string('settings:permissioncheckpassed', 'tool_objectfs')] = 'notifysuccess';
        }

        return $permissions;
    }

    /**
     * get_exception_details
     * @param \MicrosoftAzure\Storage\Common\Exceptions\ServiceException $exception
     *
     * @return string
     */
    protected function get_exception_details(\MicrosoftAzure\Storage\Common\Exceptions\ServiceException $exception) {
        $message = $exception->getErrorMessage();

        if (get_class($exception) !== 'MicrosoftAzure\Storage\Common\Exceptions\ServiceException') {
            return "Not an Azure exception : $message";
        }

        $errorcode = $this->get_body_error_code($exception);

        $details = ' ';

        if ($message) {
            $details .= "ERROR MSG: " . $message . "\n";
        }

        if ($errorcode) {
            $details .= "ERROR CODE: " . $errorcode . "\n";
        }

        return $details;
    }

    /**
     * Azure settings form with the following elements:
     *
     * Storage account name.
     * Container name.
     * Shared Access Signature.
     *
     * @param admin_settingpage $settings
     * @param  \stdClass $config
     * @return admin_settingpage
     */
    public function define_client_section($settings, $config) {

        $settings->add(new \admin_setting_heading('tool_objectfs/azure',
            new \lang_string('settings:azure:header', 'tool_objectfs'), $this->define_client_check()));

        $settings->add(new \admin_setting_configtext('tool_objectfs/azure_accountname',
            new \lang_string('settings:azure:accountname', 'tool_objectfs'),
            new \lang_string('settings:azure:accountname_help', 'tool_objectfs'), ''));

        $settings->add(new \admin_setting_configtext('tool_objectfs/azure_container',
            new \lang_string('settings:azure:container', 'tool_objectfs'),
            new \lang_string('settings:azure:container_help', 'tool_objectfs'), ''));

        $settings->add(new \admin_setting_configpasswordunmask('tool_objectfs/azure_sastoken',
            new \lang_string('settings:azure:sastoken', 'tool_objectfs'),
            new \lang_string('settings:azure:sastoken_help', 'tool_objectfs'), ''));

        // Admin_setting_check only exists in 4.5+, in lower versions fallback to a basic description.
        if (class_exists('admin_setting_check')) {
            $settings->add(new admin_setting_check('tool_objectfs/check_tokenexpiry', new token_expiry(), true));
        } else {
            $summary = (new token_expiry())->get_result()->get_summary();
            $settings->add(new admin_setting_description('tool_objectfs/tokenexpirycheckresult',
                get_string('checktoken_expiry', 'tool_objectfs'), $summary));
        }

        return $settings;
    }

    /**
     * Returns token expiry time
     * @return int
     */
    public function get_token_expiry_time(): int {
        if (empty($this->config->azure_sastoken)) {
            return -1;
        }

        // Parse the sas token (it just uses url parameter encoding).
        $parts = [];
        parse_str($this->config->azure_sastoken, $parts);

        // Get the 'se' part (signed expiry).
        if (!isset($parts['se'])) {
            // Assume expired (malformed).
            return 0;
        }

        // Parse timestamp string into unix timestamp int.
        $expirystr = $parts['se'];
        return strtotime($expirystr);
    }

    /**
     * Extract an error code from the XML response.
     *
     * @link https://docs.microsoft.com/en-us/rest/api/storageservices/common-rest-api-error-codes
     * @link https://docs.microsoft.com/en-us/rest/api/storageservices/blob-service-error-codes
     *
     * @param ServiceException $e The exception that contains the XML body.
     * @return string The error code.
     */
    private function get_body_error_code(\MicrosoftAzure\Storage\Common\Exceptions\ServiceException $e) {
        // Casting the stream content to a string will give us the HTTP body content.
        $body = (string) $e->getResponse()->getBody();

        $xml = simplexml_load_string($body);

        if ($xml instanceof SimpleXMLElement) {
            return (string) $xml->Code;
        }

         return '';
    }
}
