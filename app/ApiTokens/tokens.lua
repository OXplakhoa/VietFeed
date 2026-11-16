-- All arguments contain identifiers/hashes, never raw credentials.
-- KEYS[1] carries the connection prefix, including for dynamic keys below.
local root, op, grace = KEYS[1], ARGV[1], tonumber(ARGV[2])
local now = tonumber(redis.call('TIME')[1])
local function key(kind, id) return root .. kind .. ':' .. id end
local function expect(k, kind)
    local actual = redis.call('TYPE', k).ok
    if actual ~= 'none' and actual ~= kind then error('Invalid token state type') end
end
local function read(k)
    expect(k, 'string')
    local value = redis.call('GET', k)
    if value then return cjson.decode(value) end
end
local function index(uid, family, deadline)
    local k = key('user', uid)
    redis.call('ZREMRANGEBYSCORE', k, '-inf', now)
    redis.call('ZADD', k, deadline, family)
    local last = redis.call('ZREVRANGE', k, 0, 0, 'WITHSCORES')
    redis.call('EXPIREAT', k, tonumber(last[2]))
end
local function put(kind, hash, uid, family, ttl)
    redis.call('SET', key(kind, hash), cjson.encode({uid=uid, family=family, expires=now+ttl}), 'EX', ttl+grace)
end
if op == 'issue' then
    local uid, family, access, refresh = ARGV[3], ARGV[4], ARGV[5], ARGV[6]
    local at, rt = tonumber(ARGV[7]), tonumber(ARGV[8])
    expect(key('user', uid), 'zset')
    local deadline = now + math.max(at, rt) + grace
    redis.call('SET', key('family', family), cjson.encode({uid=uid, epoch=tonumber(ARGV[9])}), 'EXAT', deadline)
    put('access', access, uid, family, at)
    put('refresh', refresh, uid, family, rt)
    index(uid, family, deadline)
    return {'ok'}
end
if op == 'revoke_user' then
    local k = key('user', ARGV[3])
    expect(k, 'zset')
    local families = redis.call('ZRANGE', k, 0, -1)
    for _, family in ipairs(families) do redis.call('DEL', key('family', family)) end
    redis.call('DEL', k)
    return {'ok'}
end
if op == 'revoke_family' then
    local k = key('family', ARGV[3])
    expect(k, 'string')
    local stored = redis.call('GET', k)
    local uid = stored and cjson.decode(stored).uid
    if uid then
        expect(key('user', uid), 'zset')
        redis.call('ZREM', key('user', uid), ARGV[3])
        redis.call('DEL', k)
    end
    return {'ok'}
end
local kind = op == 'access' and 'access' or 'refresh'
local k = key(kind, ARGV[3])
local token = read(k)
if not token then return {'invalid_' .. kind .. '_token'} end
if token.expires <= now then return {kind .. '_token_expired'} end
local familyKey = key('family', token.family)
expect(familyKey, 'string')
local family = read(familyKey)
if not family then return {'token_family_revoked'} end
if kind == 'refresh' and token.used then
    expect(key('user', token.uid), 'zset')
    redis.call('DEL', familyKey)
    redis.call('ZREM', key('user', token.uid), token.family)
    return {'refresh_token_reused'}
end
if op == 'rotate' then
    local at, rt = tonumber(ARGV[6]), tonumber(ARGV[7])
    expect(key('user', token.uid), 'zset')
    local deadline = math.max(now + math.max(at, rt) + grace, now + redis.call('TTL', familyKey))
    -- KEEPTTL retains the old token's original expiry/reuse horizon.
    token.used = true
    redis.call('SET', k, cjson.encode(token), 'KEEPTTL')
    redis.call('EXPIREAT', familyKey, deadline)
    put('access', ARGV[4], token.uid, token.family, at)
    put('refresh', ARGV[5], token.uid, token.family, rt)
    index(token.uid, token.family, deadline)
end
return {'ok', token.uid, token.family, tostring(family.epoch or 0)}
