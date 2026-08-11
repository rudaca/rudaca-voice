<?php

namespace Tests\Support;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * A throwaway RSA keypair standing in for a Microsoft Entra tenant's signing
 * key, used to build a fake JWKS document and sign test ID tokens — the test
 * suite never talks to real Microsoft endpoints.
 *
 * The keys below are fixed, test-only material (never used for anything but
 * signing tokens inside this suite) rather than generated at runtime via
 * `openssl_pkey_new()`, because that call requires an `openssl.cnf` that
 * isn't guaranteed to be discoverable in every local PHP/OpenSSL setup;
 * parsing an already-generated PEM has no such dependency.
 */
class MicrosoftOidcFixture
{
    public const KID = 'test-signing-key';

    private const PRIMARY_KEY = <<<'PEM'
    -----BEGIN PRIVATE KEY-----
    MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDFS2iqiwRd1sg1
    p2nc5VQT3lXn4+5eXxumJoRvc+o3+AVhjIbPreeNCnd6gTPnwVcLx0ot1TF7mc+K
    k8e8N4kB1abDMrUs0IfK8bScxxoR45hHRW4kkA13RzpeWNU34pYxaiyIIh9Hq6rP
    3BCqLxdq3B3qlg3Oxzrq8RulrbqiafDTSHzky9Y8pM7lmpPHHX5HI/IGEYSbEZv9
    KsuS8I4U8/dOiGCibu9MgL2dnYeiMGWWY+IhU7zRG07DJSpdj9FNJ5qHxJ3g8pLd
    2zDqDQXkwpRom8LR1wJsXSdvXXMKlLFfVn7kNW9sLMQ6TCNCacU7jPG34nQFS0gu
    mkuNUb8tAgMBAAECggEADDlOXFatqBTBJdXgEMAis/JwFyR/pdg1sdcsGG2xotMa
    nj8jMSXgtVPjMDNmIGNRvdiUp39QiAxxF/BtDSPRuMvgf7KaGBM5fqD4JEiPvHEC
    A9Rw7RIBy5SdsK/HUiWXUat2495dWsbvl95WJ/0v8b5+mgS/CUM9ysWyhVLcv+g9
    lKi+NeYJa++Zf1kCgO9ZQ4DD5A/i/it56k2iRB7+dNqdL7dL73fiQfWVji1v65Mg
    tCAz6bweQ222mZ2NYRMXHm28kdCiH9/P7wRFhO0UwCMGQCL5mOKK4Y/3ynxUYjCS
    zLWRdN3x02gXIAXUYQcQRs4/abMcA1i5qhvCIx4KsQKBgQDqPBvxE0uScaHiMdfZ
    YV02h724+z2wdbzlyKbr14zRvZPKavU4IHtO5e0zYql0vIcjdw7dZtmHrEAwHpuL
    hh0szC1BbijPoHajqBaOHaCdYdGYc7/IWiz0j56+MQg9ClXu83LuJjWcmensRU0m
    dlKplThS7ikmThISUe6LSXLgOQKBgQDXoJQ79g1n02UvyXK7SJn3j9xIsE6Li1eL
    IHaZKr6kzZVSKP1x6JI0ofndBne9d+0EAXQ5WDexmDt7/maRJwu9FzoGaVFqKku3
    THOum3YrV7y5z4WG6YRuIKeAw0I178rxoBmFzsag1bvkBfVY+ufmCXgyObFguhvM
    bWYZzIMulQKBgQCBDKrsUBAj170zzPg6CL19TJ4Ha0xaixOOmdT1POWVrNfe/ryp
    tqOZHW5pECOCcFgX/wFOk9qnOAyJNmPGJBaw1rDcSp/rfGHA2tvKYqJZ80mxr5vq
    +1unRfVUndkHIEmmA7S/ZofFBrttc+UEms4CJndIoXREaWDlfQRq8wV6aQKBgQCt
    9sXhYnAKVgkK9tHzu21Mx+oHUwbrmm30tyo4BL5uo9ZWxO9FWUer4wp9gdxVJk44
    rxufsEaup41GSkdh0EiuM/ECfzHKH3ma1rl2I8LA0TZYCs9Fu1y2pO2++smOTnpD
    WtF8nQivdgDyxMPfF/7EHtu0Wct7qGsJETIQmmkzyQKBgCnYUBnO48ralJjRysQn
    KuTuXheI6dYD89MQFs3AsoOxwAQHB1EcI1sSSY18rKYVIVS5FD9k25NIeNjmF1tv
    gE4rnADRvsQsxbBlxZJgh7HG8vvr9jNvsKnqDadX+pUDiJh5mDrM+Hq+IIKudPJR
    X/Yp4Coi+KMMH5FFctNN1kMr
    -----END PRIVATE KEY-----
    PEM;

    private const ALTERNATE_KEY = <<<'PEM'
    -----BEGIN PRIVATE KEY-----
    MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQC5oNBpReVk55C5
    HKOG7LQJiI9gGGCG9LH6Hi1oZ7enJsoY/NXcOTwbevEyEP326Rj4Tc0MZc6gAh4U
    ijyqiu1dfbGdN47m8vpXnQvES624NvrZM7IfC49iwndRYW5lPcmNLesvaW178lBj
    GxZ267k3sd+PRLo+P3iybYDDyzanbSxYivZeUGS2RIf+CAZqGGRj90PeR1P45wdj
    JF7bspNdwwADuOiVQtWo9CKnfRUOiDGCq76pt2T/EZzyv/a5lTFJAZyilEDNEhFA
    1MMvGSescEMhk+zERWgsfvVDhZOGGkWRsevlDzQb4e9xGeJ5UYxFnvrmmINmcj4c
    JiLwETdnAgMBAAECggEAKdps8zd7u88MTfh7lGndRAMC1LdaWENmt47DTTWJboor
    6gtjysYq28VSCahUIq4235FnKKLxvhkMIDR07jzpvPkgqWKD+WWSdVum8gjgL9dR
    otRpll9cYneXQIWXxwLx5T+Ttfpu4ZHmdxn97C1E8x5LWjm0RmG4PH69GChC9+ee
    B5IxhMuLVf3fQKM/tA1M8SrJCwtfG2UpGkmtY9utGJF1Ej7GR4BgzkDJ02DJ5lih
    IVFdGTWuLaO/D3t+5xDDQX6GG50ydTnT/H3lU4Ohhbn3bKV9ZFUcRhdE5XX40cEL
    vfm+2abCJeyrVlksgQcB+BItgJCnEFoVWH9IoMfhiQKBgQD4AJtFmabKgfJKtbAx
    Q/ukUoqhqEmjM8ckIeaZhNMOKcxKPKlH5Lk7GC+pCJ/BhWNlqUlZd/Njfc6nrRvR
    TnXhv6le2Yf7xmiG8/37Q/QhNTrmXBs6B4zyOAtyFKvVfoirQAuyi8YNWSGJfiJQ
    ovoBORVV+BeFpK05K+WdgPv+eQKBgQC/nUZkFIUmESGGxjUxxU9UhCdoooYRmjcN
    D3z+ocIqC1YLyq9CDoWYrvi+HrYC8nGCzM6A53agZwqxO8qDka0BNOPcNatPYzlH
    zVApY7M1lTuHBBW2Vc1wc2Xi/m4UwyWS9J2FdVvUvfA8dLQk4wGWEPG9NUpUSRvC
    3Dshi+/s3wKBgQCoAGEvKQNgM99a1PHirdcOXgwjrskTkcPZqk14ug3vjkkiES3r
    0fnZGm1O6NSwWBgZijByN1vdjiAsXox1od0hbKDj7CC+Yo30vdzUFhiPVmvsGYEo
    Mm08uNKoGXC+U9VpjR1femhUKokZhyTf00fhBDZ74nCsy/28uQv3QqVyoQKBgF3M
    DlYVWWxd/GxuAEIh1QiJPIVS8ZASTpp9F3HKGzLbo75X9FzAoRMxq5/dhrmAlqIx
    wXCGXaJ9blV98E9hcy/hBR2ZxAcziimkznXEUUiMibw4+qvr6on+Y0SvyZEuSelb
    BvT6kv5cEAp4EmrwGKmuF7fIK1+A/i7wAZ4VU1g/AoGBALjZrb4Sq1c6jMKPGujd
    Tbzft7hn9UjcBtANAIPY6E7fPDnZKJO0SezR2AYYYhCrKtei/tjwL92cZHN2BcD6
    evBkY6xr/Xt7CSM44/LBZCu4ifZHFA2WdN9D2FZB2Bs3O0Am7mBHOc1Vk8lICtoA
    h8nfgWOiqQH5EThP11QCHS6f
    -----END PRIVATE KEY-----
    PEM;

    /** @var \OpenSSLAsymmetricKey */
    private $privateKey;

    public static function alternate(): self
    {
        return new self(self::ALTERNATE_KEY);
    }

    public function __construct(?string $pem = null)
    {
        $this->privateKey = openssl_pkey_get_private($pem ?? self::PRIMARY_KEY);
    }

    /**
     * The tenant's JWKS document, as returned by the discovery endpoint.
     *
     * @return array{keys: array<int, array<string, string>>}
     */
    public function jwks(): array
    {
        $details = openssl_pkey_get_details($this->privateKey);

        return [
            'keys' => [
                [
                    'kty' => 'RSA',
                    'use' => 'sig',
                    'kid' => self::KID,
                    'n' => $this->base64UrlEncode($details['rsa']['n']),
                    'e' => $this->base64UrlEncode($details['rsa']['e']),
                ],
            ],
        ];
    }

    /**
     * Sign a set of claims as an RS256 ID token using the fixture's private key.
     *
     * @param  array<string, mixed>  $claims
     */
    public function idToken(array $claims): string
    {
        return JWT::encode($claims, $this->privateKey, 'RS256', self::KID);
    }

    /**
     * A Guzzle client whose token endpoint always returns the given ID token
     * alongside a placeholder access token, for injection into
     * MicrosoftOAuthClientFactory in place of a real HTTP client.
     */
    public function tokenExchangeClient(string $idToken): Client
    {
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'access_token' => 'fake-access-token',
                'id_token' => $idToken,
            ])),
        ]);

        return new Client(['handler' => HandlerStack::create($mock)]);
    }

    /**
     * Standard claims for a valid token, merged with the given overrides.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function baseClaims(array $overrides = []): array
    {
        $tenant = $overrides['tid'] ?? fake()->uuid();

        return array_merge([
            'iss' => "https://login.microsoftonline.com/{$tenant}/v2.0",
            'tid' => $tenant,
            'iat' => now()->timestamp,
            'nbf' => now()->timestamp,
            'exp' => now()->addMinutes(10)->timestamp,
            'sub' => fake()->uuid(),
        ], $overrides);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
