<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Mezzio\Handler;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Mezzio\Exception\MissingSessionException;
use Contenir\FormBuilder\Mezzio\Http\Responder;
use Contenir\FormBuilder\Mezzio\Http\ReturnTarget;
use Contenir\FormBuilder\Mezzio\Loader\FormLoaderInterface;
use Contenir\FormBuilder\Mezzio\Session\RequestSession;
use Contenir\FormBuilder\Mezzio\State\FormStateStash;
use Contenir\FormBuilder\Mezzio\Token\SubmissionTokens;
use Contenir\FormBuilder\Mezzio\Token\TokenReplacerBuilder;
use Contenir\FormBuilder\Service\FormBuilderService;
use Contenir\Storage\Exception\StorageException;
use JsonException;
use Laminas\Form\Exception\ExceptionInterface as FormException;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Random\RandomException;
use RuntimeException;

use function is_array;
use function is_scalar;
use function is_string;
use function str_contains;
use function strtoupper;

/**
 * Public POST endpoint for site submissions, routed at
 * `/forms/submit/{slug}` by default.
 *
 * The handler resolves the form by the `slug` route attribute through the
 * {@see FormLoaderInterface} and runs the submission through the
 * {@see SubmissionPipeline} (CSRF check, build, validate, spam detection,
 * uploads, observers). It answers JSON when the `Accept` header asks for
 * `application/json`, and redirects otherwise.
 *
 * On success the form's {@see SuccessSettings} decide the answer:
 * `redirect_referrer` (default) and `inline_message` redirect back to the
 * referrer with `?submit={slug}`, `redirect_url` redirects to the configured
 * URL. Invalid submissions redirect back with no query: the values and
 * validation messages are stashed in the session by slug for the next
 * render. Spam is answered exactly like a success, so bots learn nothing.
 *
 * Redirects back only ever go to this site, see {@see ReturnTarget}.
 *
 * @api
 */
final readonly class SubmitHandler implements RequestHandlerInterface
{
    public const string ROUTE_NAME = 'formbuilder.submit';

    public function __construct(
        private FormLoaderInterface $loader,
        private SubmissionPipeline $pipeline,
        private FormStateStash $stash,
        private TokenReplacerBuilder $tokens,
        private Responder $responder,
    ) {}

    /**
     * The submission context passed to observers: ip, user_id (null), meta
     * (user_agent, referer) and site (`base_url` from the request, unless the
     * host is unknown).
     *
     * @return array{ip: string, user_id: null, meta: array{user_agent: string, referer: string}, site: array<string, string>}
     *
     * @mago-expect analysis:mixed-assignment Server parameters are untyped; only scalars are used.
     */
    private static function context(ServerRequestInterface $request): array
    {
        $ip   = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $uri  = $request->getUri();
        $host = $uri->getHost();
        $port = $uri->getPort();

        return [
            'ip'      => is_scalar($ip) ? (string) $ip : '',
            'user_id' => null,
            'meta'    => [
                'user_agent' => $request->getHeaderLine('User-Agent'),
                'referer'    => $request->getHeaderLine('Referer'),
            ],
            'site'    => '' === $host
                ? []
                : ['base_url' => "{$uri->getScheme()}://{$host}" . (null === $port ? '' : ":{$port}")],
        ];
    }

    /**
     * Answers JSON (405, 400, 404, 422 or 200) when the client accepts JSON
     * or the request cannot be handled, and otherwise redirects.
     *
     * @throws FormException When Laminas rejects the built form.
     * @throws JsonException When a JSON payload cannot be encoded.
     * @throws MissingSessionException When no session middleware ran before this handler.
     * @throws RandomException When no secure random source is available.
     * @throws RuntimeException When an upload cannot be staged.
     * @throws StorageException When the storage backend cannot store an upload.
     *
     * @mago-expect analysis:mixed-assignment Route attributes are untyped; the slug is checked with is_string().
     */
    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ('POST' !== strtoupper($request->getMethod())) {
            return $this->responder->json(['error' => 'Method not allowed'], status: 405)->withHeader('Allow', 'POST');
        }

        $slug = $request->getAttribute('slug');
        if (! is_string($slug) || '' === $slug) {
            return $this->responder->json(['error' => 'Missing slug'], status: 400);
        }

        $form = $this->loader->loadBySlug($slug);
        if (null === $form) {
            return $this->responder->json(['error' => 'Form not found'], status: 404);
        }

        return $this->submit($request, $form);
    }

    /**
     * @throws FormException
     * @throws JsonException
     * @throws MissingSessionException
     * @throws RandomException
     * @throws RuntimeException
     * @throws StorageException
     */
    private function submit(ServerRequestInterface $request, FormDefinition $form): ResponseInterface
    {
        $session = RequestSession::from($request);
        $body    = $request->getParsedBody();
        /** @var array<string, mixed> $post */
        $post      = is_array($body) ? $body : [];
        $target    = ReturnTarget::fromRequest($request, $post);
        $context   = self::context($request);
        $wantsJson = str_contains($request->getHeaderLine('Accept'), 'application/json');
        $result    = $this->pipeline->submit($form, $session, $post, $request->getUploadedFiles(), $context);

        if (! $result->valid && ! $result->isSpam) {
            if ($wantsJson) {
                return $this->responder->json(['ok' => false, 'errors' => $result->errors], status: 422);
            }

            unset($post[ReturnTarget::ANCHOR_FIELD], $post[FormBuilderService::CSRF_NAME]);
            $this->stash->store($session, $form->slug, $post, $result->errors);

            return $this->responder->redirect($target->url(null));
        }

        $success = SuccessSettings::fromForm($form);
        $tokens  = new SubmissionTokens(
            $this->tokens->build($context['site']),
            $form,
            $result->values,
            null === $result->entryId ? [] : ['id' => $result->entryId],
        );

        if ($wantsJson) {
            return $this->responder->json($success->payload($tokens), status: 200);
        }

        return $this->responder->redirect($success->redirectUrl($tokens) ?? $target->url($form->slug));
    }
}
