<?php

/**
 * Copyright (c) 2010 Matthias Plappert <matthiasplappert@gmail.com>
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software
 * and associated documentation files (the "Software"), to deal in the Software without restriction,
 * including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do
 * so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all copies or substantial
 * portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT
 * LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
 * WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE
 * SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

namespace CloudApp;
use CloudApp\Exception;

// Type definitions
define('CLOUD_API_TYPE_ALL',      null);
define('CLOUD_API_TYPE_BOOKMARK', 'bookmark');
define('CLOUD_API_TYPE_VIDEO',    'video');
define('CLOUD_API_TYPE_IMAGE',    'image');
define('CLOUD_API_TYPE_TEXT',     'text');
define('CLOUD_API_TYPE_ARCHIVE',  'archive');
define('CLOUD_API_TYPE_AUDIO',    'audio');
define('CLOUD_API_TYPE_OTHER',    'unknown');

/**
 * Cloud_API is a simple PHP wrapper for the CloudApp API using cURL.
 *
 * @author Matthias Plappert
 */
class API
{
    /**
     * The email address of an user. Used for authentication.
     *
     * @var string
     */
    private $_email = null;

    /**
     * The password of an user. Used for authentication.
     *
     * @var string
     */
    private $_password = null;

    /**
     * The user agent that is send with every request. You should set this to something
     * that identifies your app/script.
     *
     * @var string
     */
    private $_user_agent = 'Cloud API PHP wrapper';

    /**
     * The cURL handler used for all connections.
     *
     * @var ressource
     */
    private $_ch = null;

    /**
     * Initializes the class. You can pass the user’s email and password and set your user agent.
     * However, you can modify or add all values later.
     *
     * @param string $email
     * @param string $password
     * @param string $user_agent
     */
    public function __construct($email = null, $password = null, $user_agent = null) {
        // Set email and password
        $this->setEmail($email);
        $this->setPassword($password);

        // Set user agent
        $this->setUserAgent($user_agent);

        // Create curl instance
        $this->_ch = curl_init();
    }

    /**
     * Closes the cURL session.
     */
    public function __destruct() {
        curl_close($this->_ch);
    }

    /**
     * Sets the user’s email address.
     *
     * @param string $email
     */
    public function setEmail($email) {
        $this->_email = $email;
    }

    /**
     * Returns the user’s email address.
     *
     * @return string
     */
    public function getEmail() {
        return $this->_email;
    }

    /**
     * Sets the user’s password.
     *
     * @param string $password
     */
    public function setPassword($password) {
        $this->_password = $password;
    }

    /**
     * Returns the user’s password.
     *
     * @return string
     */
    public function getPassword() {
        return $this->_password;
    }

    /**
     * Sets the user agent.
     *
     * @param string $agent
     */
    public function setUserAgent($agent) {
        $this->_user_agent = $agent;
    }

    /**
     * Returns the user agent.
     *
     * @return string
     */
    public function getUserAgent() {
        return $this->_user_agent;
    }

    /**
     * Creates a bookmark and returns the server response. Requires authentication. If $private equals null,
     * the user’s default settings are used. Set $private to true or false to explicitly make it private or
     * public. This might be useful if something is intended for Twitter sharing, for example.
     *
     * @param string $url
     * @param string $name
     * @param bool|null $private
     * @return object
     */
    public function addBookmark($url, $name = '', $private = null) {
        // Create body and run it
        $body = array('item' => array('name' => $name, 'redirect_url' => $url));
        if ($private !== null) {
            $body['item']['private'] = $private === true ? 'true' : 'false';
        }
        return $this->_execute('http://my.cl.ly/items', json_encode($body), 200, 'POST');
    }

    /**
     * Adds a file and returns the server response. Requires authentication.
     *
     * @param string $path
     * @return object
     */
    public function addFile($path) {
        // Check if file exists
        if (!file_exists($path)) {
            throw new Exception('File at path \'' . $path . '\' not found', CLOUD_EXCEPTION_FILE_NOT_FOUND);
        }

        // Check if path points to a file
        if (!is_file($path)) {
            throw new Exception('Path \'' . $path . '\' doesn\'t point to a file', CLOUD_EXCEPTION_FILE_INVALID);
        }

        // Check if file is readable
        if (!is_readable($path)) {
            throw new Exception('File at path \'' . $path . '\' isn\'t readable', CLOUD_EXCEPTION_FILE_NOT_READABLE);
        }

        // Request S3 data
        $s3 = $this->_execute('http://my.cl.ly/items/new');

        // Check if we can upload
        if(isset($s3->num_remaining) && $s3->num_remaining < 1) {
            throw new Exception('Insufficient uploads remaining. Please consider upgrading to CloudApp Pro', CLOUD_EXCEPTION_PRO);
        }

        // Create body and upload file
        $body = array();
        foreach ($s3->params as $key => $value) {
            $body[$key] = $value;
        }
        $body['file'] = '@' . $path;

        $location = $this->_upload($s3->url, $body);

        // Parse location
        $query = parse_url($location, PHP_URL_QUERY);
        $query_parts = explode('&', $query);
        foreach ($query_parts as $part) {
            $key_and_value = explode('=', $part, 2);
            if (count($key_and_value) != 2) {
                continue;
            }

            if ($key_and_value[0] != 'key') {
                continue;
            }

            // Encode key value
            $value = $key_and_value[1];
            $encoded_value = urlencode($value);

            // Replace decoded value with encoded one
            $replace_string = $key_and_value[0] . '=' . $encoded_value;
            $location = str_replace($part, $replace_string, $location);
            break;
        }

        // Get item
        return $this->_execute($location, null, 200);
    }

    /**
     * Returns all existing items. Requires authentication.
     *
     * @param int $page
     * @param int $per_page
     * @param string $type
     * @param bool $deleted
     * @return array
     */
    public function getItems($page = 1, $per_page = 5, $type = CLOUD_API_TYPE_ALL, $deleted = false) {
        $url = 'http://my.cl.ly/items?page=' . $page . '&per_page=' . $per_page;

        if ($type !== CLOUD_API_TYPE_ALL) {
            // Append type
            $url .= '&type=' . $type;
        }

        // Append deleted
        if ($deleted === true) {
            $url .= '&deleted=true';
        } else {
            $url .= '&deleted=false';
        }

        return $this->_execute($url);
    }

    /**
     * Returns detail about a specific item. No authentication required.
     *
     * @param string $url
     * @return object
     */
    public function getItem($url) {
        return $this->_execute($url, null, 200, 'GET');
    }

    /**
     * Deletes an item. Authenticiation required.
     *
     * @param string|object $href
     */
    public function deleteItem($href) {
        if (is_object($href)) {
            // Get href
            $href = $href->href;
        }

        $this->_execute($href, null, 200, 'DELETE');
    }

    private function _execute($api_url, $body = null, $expected_code = 200, $method = 'GET') {
        // Set URL
        curl_setopt($this->_ch, CURLOPT_URL, $api_url);

        // HTTP headers
        $headers = array('Content-Type: application/json',
                         'Accept: application/json');

        // HTTP Digest Authentication
        curl_setopt($this->_ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
        curl_setopt($this->_ch, CURLOPT_USERPWD, $this->_email . ':' . $this->_password);

        curl_setopt($this->_ch, CURLOPT_USERAGENT, $this->_user_agent);
        curl_setopt($this->_ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->_ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($this->_ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($this->_ch, CURLOPT_COOKIEFILE, '/dev/null'); // enables cookies
        curl_setopt($this->_ch, CURLOPT_FOLLOWLOCATION, true);

        // Add body
        curl_setopt($this->_ch, CURLOPT_POSTFIELDS, $body);

        // Execute
        $response = curl_exec($this->_ch);

        // Check for status code and close connection
        $status_code = curl_getinfo($this->_ch, CURLINFO_HTTP_CODE);
        if ($status_code != $expected_code) {
            throw new Exception('Invalid response. Expected HTTP status code \'' . $expected_code . '\' but received \'' . $status_code . '\'', CLOUD_EXCEPTION_INVALID_RESPONSE);
        }

        // Decode JSON and return result
        return json_decode($response);
    }

    private function _upload($url, $body, $expected_code = 303) {
        // Create new curl session
        $ch = curl_init($url);

        // HTTP headers
        $headers = array('Content-Type: multipart/form-data');

        // Configure curl
        curl_setopt($ch, CURLOPT_USERAGENT, $this->_user_agent);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        // Execute
        $response = curl_exec($ch);

        // Check for status code and close connection
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($status_code != $expected_code) {
            throw new Exception('Invalid response. Expected HTTP status code \'' . $expected_code . '\' but received \'' . $status_code . '\'', CLOUD_EXCEPTION_INVALID_RESPONSE);
        }

        // Close
        curl_close($ch);

        // Get Location: header
        $matches = array();
        if (preg_match("/Location: (.*?)\n/", $response, $matches) == 1) {
            return trim(urldecode($matches[1]));
        } else {
            // Throw exception
            throw new Exception('Invalid response. Location header is missing.', CLOUD_EXCEPTION_INVALID_RESPONSE);
        }
    }
}

?>
