<?php

/**
 * Faire External API v2 client for one website (brand).
 *
 * Authentication: a brand access token (X-FAIRE-ACCESS-TOKEN), or an OAuth app
 * (X-FAIRE-APP-CREDENTIALS = base64(applicationId:applicationSecret) together with
 * X-FAIRE-OAUTH-ACCESS-TOKEN).
 */
class YSRTech_Faire_Model_Api
{
    /** @var Mage_Core_Model_Website */
    protected $_website;

    protected $_requests = 0;

    public function setWebsite($website)
    {
        $this->_website = Mage::app()->getWebsite($website);
        return $this;
    }

    public function getWebsite()
    {
        return $this->_website;
    }

    protected function _helper()
    {
        return Mage::helper('ysrtech_faire');
    }

    public function get($path, array $query = [])
    {
        return $this->request('GET', $path, null, $query);
    }

    public function post($path, array $body)
    {
        return $this->request('POST', $path, $body);
    }

    public function put($path, array $body)
    {
        return $this->request('PUT', $path, $body);
    }

    public function patch($path, array $body)
    {
        return $this->request('PATCH', $path, $body);
    }

    /**
     * @return array decoded JSON
     * @throws YSRTech_Faire_Model_Api_Exception
     */
    public function request($method, $path, ?array $body = null, array $query = [])
    {
        if (!$this->_website) {
            Mage::throwException('Faire API: no website.');
        }
        $helper = $this->_helper();
        $url = rtrim((string) $helper->getConfig('api/api_url', $this->_website), '/') . '/' . ltrim($path, '/');
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($helper->getConfig('api/auth', $this->_website) === 'oauth') {
            $headers[] = 'X-FAIRE-APP-CREDENTIALS: ' . base64_encode($helper->getSecret('api/app_id', $this->_website) . ':' . $helper->getSecret('api/app_secret', $this->_website));
            $headers[] = 'X-FAIRE-OAUTH-ACCESS-TOKEN: ' . $helper->getSecret('api/oauth_token', $this->_website);
        } else {
            $headers[] = 'X-FAIRE-ACCESS-TOKEN: ' . $helper->getSecret('api/access_token', $this->_website);
        }
        $attempt = 0;
        while (true) {
            $attempt++;
            $this->_requests++;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_HTTPHEADER     => $headers,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            // rate limit (429) and server errors: wait and retry, 4 attempts
            if (($error !== '' || $status === 429 || $status >= 500) && $attempt < 4) {
                sleep(min(30, 2 ** $attempt));
                continue;
            }
            if ($error !== '') {
                throw new YSRTech_Faire_Model_Api_Exception('Faire API: ' . $error, 0);
            }
            $json = json_decode((string) $response, true);
            if ($status < 200 || $status >= 300) {
                $message = is_array($json) ? trim(($json['status_type'] ?? '') . ' ' . ($json['message'] ?? '')) : '';
                throw new YSRTech_Faire_Model_Api_Exception(
                    sprintf('Faire API %s %s: HTTP %d %s', $method, $path, $status, $message !== '' ? $message : mb_substr((string) $response, 0, 300)),
                    $status,
                );
            }
            return is_array($json) ? $json : [];
        }
    }

    /**
     * Every page of a list endpoint (cursor pagination).
     *
     * @param string $key list key in the response ("orders", "products")
     * @return Generator
     */
    public function each($path, $key, array $query = [], $limit = 50)
    {
        $query['limit'] = $limit;
        $cursor = null;
        $guard = 0;
        do {
            if ($cursor) {
                $query['cursor'] = $cursor;
            }
            $page = $this->get($path, $query);
            foreach ((array) ($page[$key] ?? []) as $row) {
                yield $row;
            }
            $next = $page['cursor'] ?? null;
            $cursor = $next && $next !== $cursor && count((array) ($page[$key] ?? [])) > 0 ? $next : null;
        } while ($cursor && ++$guard < 500);
    }

    public function getRequestCount()
    {
        return $this->_requests;
    }
}
