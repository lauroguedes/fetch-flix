<?php

namespace App\Providers;

use App\Api\ErrorCode;
use App\Api\RequestId;
use App\Http\Middleware\RecordApiUsage;
use App\Http\Requests\Api\V1\DiscoverRequest;
use App\Http\Requests\Api\V1\ResolveRequest;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * What each error status means. Only 429 and 5xx are retryable.
     *
     * @var array<int, string>
     */
    private const ERROR_RESPONSES = [
        403 => 'Missing, invalid, or insufficiently scoped token; suspended tenant; private media.',
        410 => 'A previously issued URL has expired. Re-resolve rather than retrying.',
        422 => 'Invalid payload, or media that cannot be resolved.',
        429 => 'Burst limit or daily quota exhausted. Honour Retry-After.',
        503 => 'Extractor temporarily unavailable. Retryable.',
    ];

    /**
     * Which of those each endpoint can actually answer with.
     *
     * Listed rather than applied uniformly, because documenting a status an
     * endpoint cannot return is as unhelpful as omitting one it can: /health
     * carries no throttle and takes no body, and /stream authenticates with a
     * URL signature rather than a token or a payload.
     *
     * @var array<string, array<int, int>>
     */
    private const ERRORS_BY_PATH = [
        '/v1/health' => [503],
        self::STREAM_PATH => [403, 410, 503],
        // Reads curated rows behind a token and a throttle, and calls nothing
        // upstream: it can be refused, but it cannot expire or be unresolvable.
        '/v1/categories' => [403, 429],
    ];

    /** Endpoints that take a body and a token. */
    private const DEFAULT_ERRORS = [403, 410, 422, 429, 503];

    /** Named because three of the tables below single it out. */
    public const STREAM_PATH = '/v1/stream/{asset}';

    /**
     * What a 200 means, per endpoint.
     *
     * Scramble names the Resource class when nothing says otherwise, which
     * describes our internals rather than the caller's outcome.
     */
    private const SUCCESS_RESPONSES = [
        '/v1/resolve' => 'The media is ready to download from `media.url`.',
        '/v1/discover' => 'A page of results. Empty `videos` means no match, not an error.',
        '/v1/categories' => 'Every category this deployment currently serves.',
        '/v1/health' => 'The service is answering. Check `status` for whether it is degraded.',
        self::STREAM_PATH => 'The media file. On a remote disk this is a 302 to a pre-signed URL instead.',
    ];

    /**
     * Endpoints behind a tenant token, and so the only ones reading X-Client.
     *
     * Listed rather than derived from ERRORS_BY_PATH: /v1/categories is
     * token-authenticated *and* has a custom error list, so the two questions
     * stopped having the same answer.
     */
    private const TENANT_PATHS = ['/v1/resolve', '/v1/discover', '/v1/categories'];

    /**
     * Defaults applied when an optional field is omitted.
     *
     * They live in the accessors on each FormRequest, so nothing in the
     * validation rules reveals them and a reader of the docs would otherwise
     * have to guess. `/v1/discover`'s duration ceiling matters most: omitting
     * it silently caps a feed at three minutes.
     *
     * Not a constant, because one of them is configurable: publishing a literal
     * would document a ceiling that a deployment overriding `MEDIA_*` does not
     * actually apply.
     *
     * @return array<class-string, array<string, mixed>>
     */
    private static function requestDefaults(): array
    {
        return [
            ResolveRequest::class => [
                'prefer' => 'progressive',
                'max_height' => ResolveRequest::DEFAULT_MAX_HEIGHT,
                'audio_only' => false,
            ],
            DiscoverRequest::class => [
                'max_duration_ms' => (int) config('media.discovery.default_max_duration_ms', 180000),
            ],
        ];
    }

    public function boot(): void
    {
        $this->configureProxies();
        $this->configureDefaults();
        $this->configureDevCommands();
        $this->configureApiDocs();
    }

    /**
     * Document the shared error envelope.
     *
     * Scramble infers request and response shapes from the FormRequests and
     * Resources directly, so there is nothing to annotate for the success
     * paths. Errors are the exception: they are *thrown*, never returned, so
     * no return type reveals them. They are described once here and attached
     * to every operation rather than repeated per controller.
     */
    protected function configureApiDocs(): void
    {
        Scramble::afterOpenApiGenerated(function (OpenApi $openApi): void {
            $envelope = $openApi->components->addSchema('ErrorEnvelope', Schema::fromType(
                (new ObjectType)
                    ->addProperty('status', (new StringType)->const('error'))
                    ->addProperty('code', (new StringType)
                        ->enum(array_column(ErrorCode::cases(), 'value'))
                        ->setDescription('Fixed taxonomy. Clients branch on this.'))
                    ->addProperty('message', new StringType)
                    ->addProperty('retry_after', (new IntegerType)
                        ->nullable(true)
                        ->setDescription('Seconds to wait. Null on permanent conditions.'))
                    ->addProperty('request_id', new StringType)
                    ->setRequired(['status', 'code', 'message', 'retry_after', 'request_id'])
            ));

            foreach ($openApi->paths as $path) {
                $key = '/'.ltrim($path->path, '/');

                // Everything below depends on the path, not the operation, so
                // it is built once per path rather than once per verb.
                $statuses = self::ERRORS_BY_PATH[$key] ?? self::DEFAULT_ERRORS;
                $headers = self::headersFor($key);
                $success = self::SUCCESS_RESPONSES[$key] ?? null;

                foreach ($path->operations as $operation) {
                    foreach ($statuses as $status) {
                        $operation->addResponse(
                            Response::make($status)
                                ->description(self::ERROR_RESPONSES[$status])
                                ->setContent('application/json', $envelope)
                        );
                    }

                    if ($success !== null) {
                        // A plain list, not keyed by status, and shared
                        // responses appear in it as References rather than
                        // Response objects.
                        foreach ($operation->responses as $response) {
                            if ($response instanceof Response && (int) $response->code === 200) {
                                $response->description($success);
                            }
                        }
                    }

                    $operation->addParameters($headers);
                }
            }

            self::documentDefaults($openApi);
        });
    }

    /**
     * The parameters every operation on a path carries.
     *
     * Headers live in middleware, not in a FormRequest, and the stream
     * signature is produced by `URL::temporarySignedRoute`, so nothing in any
     * signature reveals either of them to Scramble.
     *
     * @return array<int, Parameter>
     */
    private static function headersFor(string $key): array
    {
        $parameters = [
            Parameter::make(RequestId::HEADER, 'header')
                ->setSchema(Schema::fromType(new StringType))
                ->description(
                    'Correlation id, echoed back. Reuse the same value across your own '
                    .'retries of one action: a repeat replays the stored response instead '
                    .'of paying for a second extraction. Generated when absent.'
                )
                ->example('6f1c2b9e-1f2a-4c8f-9a3e-1d0e5b7c2a11'),
        ];

        if (in_array($key, self::TENANT_PATHS, true)) {
            $parameters[] = Parameter::make(RecordApiUsage::CLIENT_HEADER, 'header')
                ->setSchema(Schema::fromType(new StringType))
                ->description('Calling app, as `<platform>/<app version>`. Telemetry only.')
                ->example('ios/1.4.0');
        }

        if ($key === self::STREAM_PATH) {
            $parameters = [...$parameters, ...self::signatureParameters()];
        }

        return $parameters;
    }

    /**
     * The two query parameters that authenticate a stream URL.
     *
     * They are produced by `URL::temporarySignedRoute` rather than declared on
     * a FormRequest, so nothing in the code reveals them to Scramble. Declaring
     * them is what stops the docs page from sending an unsigned request and
     * reporting a 403 as though the endpoint were broken.
     *
     * @return array<int, Parameter>
     */
    private static function signatureParameters(): array
    {
        return [
            Parameter::make('expires', 'query')
                ->setSchema(Schema::fromType(new IntegerType))
                ->required(true)
                ->description('Unix timestamp after which the link is dead. Copied from `media.url`.'),
            Parameter::make('signature', 'query')
                ->setSchema(Schema::fromType(new StringType))
                ->required(true)
                ->description(
                    'HMAC over the rest of the URL, and the only credential this endpoint accepts. '
                    .'Copied from `media.url` — it cannot be computed by a client, so this endpoint '
                    .'cannot be exercised from this page.'
                ),
        ];
    }

    private static function documentDefaults(OpenApi $openApi): void
    {
        foreach (self::requestDefaults() as $class => $defaults) {
            $schema = $openApi->components->getSchema($class);

            if (! $schema?->type instanceof ObjectType) {
                continue;
            }

            foreach ($defaults as $field => $value) {
                $schema->type->properties[$field]?->default($value);
            }
        }
    }

    /**
     * Teach `artisan dev` about the queues this application uses.
     *
     * The framework default listens on `default` alone, silently ignoring every
     * job dispatched here: re-extraction goes to `resolve` and transfers to
     * `downloads`. Order matters — queue:listen drains left to right, so a
     * backlog of transfers cannot starve the cheap re-extractions.
     */
    protected function configureDevCommands(): void
    {
        DevCommands::except('queue');

        DevCommands::artisan(
            'queue:listen --queue=resolve,downloads,default --tries=1 --timeout=0',
            // Not "queue": `except` filters by name at run time, so reusing it
            // would exclude this replacement too.
            'queues',
        );
    }

    /**
     * Which hops may set X-Forwarded-*.
     *
     * Behind Forge's nginx the application sees the proxy's address unless it
     * trusts these headers. What depends on getting it right is the client
     * address: `/stream` and `/health` are unauthenticated, so their rate
     * limits are keyed by IP, and a trusted X-Forwarded-For is a limit an
     * anonymous caller can rotate past at will — on an endpoint that holds a
     * worker for up to `MEDIA_STREAM_WAIT_MS` per request. So the proxy is
     * listed rather than every hop trusted.
     *
     * Signed download links do not care either way: they are signed relative to
     * the root, so a scheme or host mismatch cannot invalidate one.
     */
    protected function configureProxies(): void
    {
        TrustProxies::at(array_map(
            trim(...),
            explode(',', (string) config('app.trusted_proxies')),
        ));
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
