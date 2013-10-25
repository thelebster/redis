<?php

/**
 * Predis cache backend.
 */
class Redis_Cache_Predis extends Redis_Cache_Base {

  function get($cid) {
    $client     = Redis_Client::getClient();
    $key        = $this->getKey($cid);

    $cached = $client->hgetall($key);

    if (empty($cached)) {
      return FALSE;
    }

    $cached = (object)$cached;

    if ($cached->serialized) {
      $cached->data = unserialize($cached->data);
    }

    return $cached;
  }

  function getMultiple(&$cids) {
    $client = Redis_Client::getClient();

    $ret = $keys = array();
    $keys = array_map(array($this, 'getKey'), $cids);

    $replies = $client->pipeline(function($pipe) use ($keys) {
      foreach ($keys as $key) {
        $pipe->hgetall($key);
      }
    });

    foreach ($replies as $reply) {
      if (!empty($reply)) {

        // HGETALL signature seems to differ depending on Predis versions.
        // This was found just after Predis update. Even though I'm not sure
        // this comes from Predis or just because we're misusing it.
        // FIXME: Needs some investigation.
        if (!isset($reply['cid'])) {
          $cache = new stdClass();
          $size = count($reply);
          for ($i = 0; $i < $size; ++$i) {
            $cache->{$reply[$i]} = $reply[++$i];
          }
        } else {
          $cache = (object)$reply;
        }

        if ($cache->serialized) {
          $cache->data = unserialize($cache->data);
        }

        $ret[$cache->cid] = $cache;
      }
    }

    foreach ($cids as $index => $cid) {
      if (isset($ret[$cid])) {
        unset($cids[$index]);
      }
    }

    return $ret;
  }

  function set($cid, $data, $expire = CACHE_PERMANENT) {
    $client = Redis_Client::getClient();
    $key    = $this->getKey($cid);

    $client->pipeline(function($pipe) use ($cid, $key, $data, $expire) {

      $hash = array(
        'cid' => $cid,
        'created' => time(),
        'expire' => $expire,
      );

      if (!is_scalar($data)) {
        $hash['data'] = serialize($data);
        $hash['serialized'] = 1;
      }
      else {
        $hash['data'] = $data;
        $hash['serialized'] = 0;
      }

      $pipe->hmset($key, $hash);

      switch ($expire) {

        case CACHE_TEMPORARY:
          $lifetime = variable_get('cache_lifetime', 0);
          if (0 < $lifetime) {
            $pipe->expire($key, $lifetime);
          }
          break;

        case CACHE_PERMANENT:
          // We dont need the PERSIST command we want the cache item to
          // never expire.
          break;

        default:
          // If caller gives us an expiry timestamp in the past
          // the key will expire now and will never be read.
          $pipe->expire($key, $expire - time());
          break;
      }
    });
  }

  function clear($cid = NULL, $wildcard = FALSE) {
    $many = FALSE;

    if (NULL === $cid) {
      // Do nothing. Redis expires things on its own.
      return;
    }
    else if ('*' !== $cid && $wildcard) {
      $key  = $this->getKey($cid . '*');
      $many = TRUE;
    }
    else if ('*' === $cid) {
      $key  = $this->getKey($cid);
      $many = TRUE;
    }
    else {
      $key = $this->getKey($cid);
    }

    $client = Redis_Client::getClient();

    if ($many) {
      $keys = $client->keys($key);

      // Attempt to clear an empty array will raise exceptions.
      if (!empty($keys)) {
        $client->del($keys);
      }
    }
    else {
      $client->del($key);
    }
  }

  function isEmpty() {
    // FIXME: Todo.
  }
}
