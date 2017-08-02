<?php

namespace Bolt\Extension\Soapbox\RedirectsManager;

use Bolt\Application;
use Bolt\Asset\File\JavaScript;
use Bolt\Asset\File\Stylesheet;
use Bolt\Controller\Zone;
use Bolt\Extension\SimpleExtension;
use Bolt\Filesystem\Exception\FileNotFoundException;
use Bolt\Menu\MenuEntry;
use Bolt\Version as Version;
use League\Flysystem\UnreadableFileException;
use Silex\ControllerCollection;
use Bolt\Translation\Translator;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
Use Pimple as Container;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Bolt\Extension\Soapbox\RedirectsManager\Exceptions\UnwriteableFileException;
use Bolt\Extension\Soapbox\RedirectsManager\Exceptions\RedirectsBlockNotFoundException;
use Tivie\HtaccessParser\HtaccessContainer;
use Tivie\HtaccessParser\Parser;
use Tivie\HtaccessParser\Token\Block;
use Tivie\HtaccessParser\Token\Comment;
use Tivie\HtaccessParser\Token\Directive;

/**
 * Redirects manager extension
 *
 * @author Robert Hunt <robert.hunt@soapbox.co.uk>
 */
class RedirectsManagerExtension extends SimpleExtension
{

    /**
     * @var \SplFileObject|null
     */
    private $htaccess_file = null;
    /**
     * @var HtaccessContainer|null
     */
    private $parser = null;

    /**
     * Pretty extension name
     *
     * @return string
     */
    public function getDisplayName()
    {

        return 'Redirects Manager';
    }

    /**
     * {@inheritdoc}
     */
    public function setContainer(Container $container)
    {

        parent::setContainer($container);
    }

    /**
     * {@inheritdoc}
     */
    protected function registerBackendRoutes(ControllerCollection $collection)
    {

        // Since version 3.3 there is a new mounting point for the extensions
        if (Version::compare('3.3', '>')) {
            $collection->match('/extend/redirectsmanager', [
                $this,
                'redirectsManager'
            ]);
        } else {
            $collection->match('/extensions/redirectsmanager', [
                $this,
                'redirectsManager'
            ]);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function registerMenuEntries()
    {

        $config = $this->getConfig();
        $menu   = new MenuEntry('redirectsmanager', 'redirectsmanager');
        $menu->setLabel(Translator::__('redirectsmanager.menuitem'))
             ->setIcon('fa:sitemap')
             ->setPermission($config['permission']);

        return [
            $menu,
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function registerTwigPaths()
    {

        return [
            'templates' => [
                'position'  => 'prepend',
                'namespace' => 'bolt'
            ]
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig()
    {

        return parent::getConfig();
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultConfig()
    {

        return [
            'permission' => 'files:config'
        ];
    }

    /**
     * Get a list of redirects from the .htaccess file
     *
     * @return array
     * @throws \Bolt\Extension\Soapbox\RedirectsManager\Exceptions\RedirectsBlockNotFoundException
     */
    public function getRedirects()
    {

        $htaccess_file = $this->getHtaccessFile();
        $rules         = $this->getParser($htaccess_file);

        $redirects = $this->findRedirects($rules->getIterator());

        if (empty($redirects)) {
            throw new RedirectsBlockNotFoundException();
        }

        if (!is_null($redirects['rules'])) {
            $converted = [];

            foreach ($redirects['rules'] as $rule) {
                /**
                 * @var Block $rule
                 */
                if ($rule->getName() === 'RewriteRule') {
                    $converted[] = $this->convertToHuman($rule->getArguments());
                }
            }

            $redirects['rules'] = $converted;
        }

        return $redirects;
    }

    /**
     * Get the .htaccess file from the web path (normally public/)
     *
     * @return null|\SplFileObject
     * @throws \Bolt\Extension\Soapbox\RedirectsManager\Exceptions\UnwriteableFileException
     * @throws \League\Flysystem\UnreadableFileException
     */
    private function getHtaccessFile()
    {

        $app = $this->getContainer();

        if (is_null($this->htaccess_file)) {
            $public_path   = $app['resources']->getPath('web');
            $htaccess_path = $public_path . '/.htaccess';

            if (!file_exists($htaccess_path)) {
                throw new FileNotFoundException($htaccess_path);
            }

            $file_info = new \SplFileInfo($htaccess_path);
            $file      = new \SplFileObject($htaccess_path, 'r+');

            if (!$file->isReadable()) {
                throw new UnreadableFileException($file_info);
            }

            if (!$file->isWritable() && !chmod($htaccess_path, 0666)) {
                throw new UnwriteableFileException($file_info);
            }

            $this->htaccess_file = $file;
        }

        return $this->htaccess_file;
    }

    /**
     * Get an instance of the parsed .htaccess file
     *
     * @param $htaccess_file
     *
     * @return array|\ArrayAccess|null|\Tivie\HtaccessParser\HtaccessContainer
     */
    private function getParser($htaccess_file)
    {

        if (is_null($this->parser)) {
            $parser       = new Parser();
            $this->parser = $parser->parse($htaccess_file);
        }

        return $this->parser;
    }

    /**
     * Find the redirect by checking for the existance of the string
     *
     * @param string $search
     * @param array  $redirects
     *
     * @return array|null
     */
    private function searchForRedirects($search, $redirects = [])
    {

        if (empty($redirects)) {
            $redirects = $this->getRedirects();
        }

        if (empty($redirects['rules'])) {
            return null;
        }

        $rules = [];

        foreach ($redirects['rules'] as $k => $rule) {
            if (strpos($rule['old_url'], $search) !== false || strpos($rule['new_url'], $search) !== false) {
                $rules[$k] = $rule;
            }
        }

        $redirects['rules'] = $rules;

        return $redirects;
    }

    /**
     * Find a redirect by it's URL
     *
     * @param string $url
     * @param array  $redirects
     * @param null   $key
     *
     * @return int|null|string
     */
    private function findRedirect($url, $redirects = [], $key = null)
    {

        if (empty($redirects)) {
            $redirects = $this->getRedirects();
        }

        if (empty($redirects['rules'])) {
            return null;
        }

        if (is_null($key)) {
            $rules = $redirects['rules'];
        } else {
            $rules = array_slice($redirects['rules'], $key, null, true);
        }

        foreach ($rules as $k => $rule) {
            if ($rule['old_url'] === $url) {
                return $k;
            }
        }

        return null;
    }

    /**
     * Find all the redirects in the parsed rules
     *
     * @param \ArrayIterator $rules
     * @param array          $redirects
     *
     * @return array
     */
    private function findRedirects(\ArrayIterator $rules, $redirects = [])
    {

        if (count(array_keys($redirects)) === 0) {
            $redirects = [
                'start' => null,
                'end'   => null,
                'rules' => null,
                'path'  => []
            ];
        }

        foreach ($rules as $k => $rule) {
            if ($rule instanceof Block && $rule->hasChildren()) {
                $redirects = $this->findRedirects($rule->getIterator(), $redirects);

                if (!is_null($redirects['rules'])) {
                    $redirects['path'][] = $k;

                    break;
                }

                continue;
            }

            if ($rule instanceof Comment && $rule->getText() === '### Redirects Manager block') {
                if (!is_null($redirects['start'])) {
                    $redirects['rules'] = null;
                }

                $redirects['start'] = $k;

                continue;
            } else if ($rule instanceof Comment && $rule->getText() === '### END Redirects Manager block') {
                $redirects['end'] = $k;

                break;
            }

            if (!is_null($redirects['start']) && is_null($redirects['end'])) {
                $redirects['rules'][] = $rule;
            }
        }

        if (is_null($redirects['end'])) {
            $redirects['rules'] = null;
        }

        return $redirects;
    }

    /**
     * Converts the escaped rule arguments into human readable versions
     *
     * @param array $arguments
     *
     * @return array
     */
    private function convertToHuman($arguments)
    {

        $old_url = $arguments[0];
        $new_url = $arguments[1];
        $type    = $arguments[2];

        $old_url = '/' . ltrim(preg_replace('/\(\/\)\?\$(\s)?/', '', urldecode(preg_replace('/\\s/', ' ', stripslashes(htmlentities($old_url))))), '^/');
        $new_url = preg_replace('/\$\d+/', '', urldecode(preg_replace('/\\s/', ' ', stripslashes(htmlentities($new_url)))));
        $type    = preg_replace('/(\[(.*)R=)(\d+)(.*)/', '$3', $type);

        return compact('old_url', 'new_url', 'type');
    }

    /**
     * Converts the url to an escaped rule
     *
     * @param string $url
     * @param string $find
     * @param string $replace
     *
     * @return mixed
     */
    private function convertToRule($url, $find, $replace)
    {

        return preg_replace('/' . $find . '/', $replace, preg_quote(html_entity_decode(urldecode($url))));
    }

    /**
     * Menueditor route
     * Show the redirects manager and handle form submissions
     *
     * @param  Application $app
     * @param  Request     $request
     *
     * @return Response|RedirectResponse
     */
    public function redirectsManager(Application $app, Request $request)
    {

        $config = $this->getConfig();

        $assets = [
            new JavaScript('redirectsmanager.js'),
            new Stylesheet('redirectsmanager.css')
        ];

        foreach ($assets as $asset) {
            $asset->setZone(Zone::BACKEND);

            if ($asset->getType() === 'javascript') {
                $asset->setLate(true);
            }

            $file = $this->getWebDirectory()
                         ->getFile($asset->getPath());
            $asset->setPackageName('extensions')
                  ->setPath($file->getPath());
            $app['asset.queue.file']->add($asset);
        }

        // Block unauthorized access...
        if (!$app['users']->isAllowed($config['permission'])) {
            throw new AccessDeniedException(Translator::__('redirectsmanager.notallowed'));
        }

        $redirects = $this->getRedirects();
        $search    = null;

        if ($request->isMethod('GET')) {
            $query = $request->query->all();

            if (!empty($query['search'])) {
                $search = $query['search'];

                $redirects = $this->searchForRedirects($search, $redirects);
            }
        }

        // Save the POSTed redirects
        if ($request->isMethod('POST')) {
            $this->validateCsrfToken();

            $form_values = $request->request->all();

            $saved = false;

            if (!empty($form_values)) {
                $saved = $this->saveRedirects($redirects, $form_values);
            }

            if ($saved) {
                $app['logger.flash']->success(Translator::__('redirectsmanager.flash.saved'));
                $app['logger.system']->info('Redirects have been saved/updated', ['event' => 'content']);

                // Since version 3.3 there is a new mounting point for the extensions
                if (Version::compare('3.3', '>')) {
                    $redirect_url = '/extend/redirectsmanager';
                } else {
                    $redirect_url = '/extensions/redirectsmanager';
                }

                if (!empty($form_values['search'])) {
                    $redirect_url .= '?search=' . urlencode($form_values['search']);
                }

                return new RedirectResponse($redirect_url);
            }

            $app['logger.flash']->success(Translator::__('redirectsmanager.flash.error'));
        }

        $page = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_NUMBER_INT);

        if (!$page) {
            $page = 1;
        }

        $per_page = 40;
        $total    = 1;
        $surr     = 5;

        if (!empty($redirects['rules'])) {
            $total = ceil(count($redirects['rules']) / $per_page) - 1;

            if ($total < 1) {
                $total = 1;
            }

            if ($page === 1) {
                $offset = 0;
            } else {
                $offset = ($page - 1) * $per_page;
            }

            $redirects['rules'] = array_slice($redirects['rules'], $offset, $per_page, true);
        }

        $html = $app['twig']->render("@bolt/redirectsmanager.twig", compact('redirects', 'per_page', 'page', 'total', 'surr', 'search'));

        return new Response($html);
    }

    /**
     * Process the form values and then save them to the .htacess file
     *
     * @param array $redirects
     * @param array $form_values
     *
     * @return bool
     */
    private function saveRedirects($redirects, $form_values)
    {

        $key = null;

        foreach ($form_values['old_url'] as $k => $value) {
            $is_new = false;

            if ($value === '' || $form_values['new_url'][$k] === '') {
                continue;
            }

            if (empty($form_values['original_old_url'][$k])) {
                $is_new = true;
            } else {
                $value = $form_values['original_old_url'][$k];
            }

            $key = $this->findRedirect($value, $redirects, $key);

            if (is_null($key) && !$is_new) {
                return false;
            } else if ($is_new) {
                $redirects['end']++;
                $redirects['rules'][] = [
                    'old_url' => $form_values['old_url'][$k],
                    'new_url' => $form_values['new_url'][$k],
                    'type'    => $form_values['type'][$k],
                    'delete'  => $form_values['delete'][$k]
                ];
            } else {
                $redirects['rules'][$key] = [
                    'old_url' => $form_values['old_url'][$k],
                    'new_url' => $form_values['new_url'][$k],
                    'type'    => $form_values['type'][$k],
                    'delete'  => $form_values['delete'][$k]
                ];
            }
        }

        return !!$this->saveToFile($redirects);
    }

    /**
     * Save the processed form values to the .htaccess file
     *
     * @param array $redirects
     *
     * @return bool|int
     */
    private function saveToFile($redirects)
    {

        if (count($redirects['path'])) {
            $paths = array_reverse($redirects['path']);
            $path  = array_shift($paths);

            $this->parser->offsetSet($path, $this->setChildren($paths, $this->parser->offsetGet($path), $redirects));
            $htaccess = $this->parser;
        } else {
            $htaccess = $this->parser->splice($redirects['start'], $redirects['end'] + 1, $redirects['rules']);
        }

        $test = $this->htaccess_file->fwrite((string) $htaccess);

        if ($test) {
            $this->htaccess_file->ftruncate(0);

            return file_put_contents($this->htaccess_file->getRealPath(), (string) $htaccess, LOCK_EX);
        }

        return false;
    }

    /**
     * Follow the paths array to return a final list of children
     * which has been updated to match the form values
     *
     * @param array           $paths
     * @param Directive|Block $rules
     * @param array           $redirects
     *
     * @return Directive|Block
     */
    private function setChildren($paths, $rules, $redirects)
    {

        if ($rules->hasChildren()) {
            $path = array_shift($paths);

            if (!is_null($path) && $rules->offsetExists($path)) {
                $rule = $rules->offsetGet($path);

                if (count($paths)) {
                    $rules->offsetSet($path, $this->setChildren($paths, $rule, $redirects));
                } else {
                    $rule = $this->addChildrenBlocks($rule, $redirects);

                    $rules->offsetSet($path, $rule);
                }
            } else {
                $rules = $this->addChildrenBlocks($rules, $redirects);
            }
        } else {
            foreach ($redirects['rules'] as $rule) {
                $block = $this->createRewriteRule($rule);

                $rules->addChild($block);
            }
        }

        return $rules;
    }

    /**
     * Loop and add/update/delete the different rewrite rules
     *
     * @param HtaccessContainer|Directive|Block $rule
     * @param                                   $redirects
     *
     * @return mixed
     */
    private function addChildrenBlocks($rule, $redirects)
    {

        $i      = 0;
        $offset = 0;

        foreach ($rule as $k => $r) {
            if ($k < $redirects['start']) {
                continue;
            }

            if ($k > $redirects['end']) {
                break;
            }

            if ($r instanceof Directive) {
                $block = $this->createRewriteRule($redirects['rules'][$i]);
                $rule->offsetSet($k, $block);

                if (!empty($redirects['rules'][$i]['delete'])) {
                    $rule->offsetUnset($k);
                }

                $i++;
                $offset = $k;
            }
        }

        if (count($redirects['rules']) > $i) {
            $remaining_rules = array_slice($redirects['rules'], $i, null, true);

            $offset++;
            $reset_offset   = $offset;
            $after_children = [];

            while ($rule->offsetExists($offset)) {
                $after_children[] = $rule->offsetGet($offset);
                $rule->offsetUnset($offset);
                $offset++;
            }

            $offset = $reset_offset;

            foreach ($remaining_rules as $r) {
                $block = $this->createRewriteRule($r);
                $rule->offsetSet($offset, $block);
                $offset++;
            }

            if (count($after_children)) {
                foreach ($after_children as $after_child) {
                    $rule->addChild($after_child);
                }
            }
        }

        return $rule;
    }

    /**
     * Escape and format the different parts of a rewrite rule and return the new Directive
     *
     * @param array $rule
     *
     * @return \Tivie\HtaccessParser\Token\Directive
     */
    private function createRewriteRule($rule)
    {

        $rewrite_rule = new Directive('RewriteRule');

        $old_url = $this->trimUrl($this->removeDomain($rule['old_url']));
        $new_url = $this->fixQueryString($this->prependSlash($this->trimUrl($this->removeDomain($rule['new_url']))));
        $type    = '[R=' . $rule['type'] . ',L]';

        $old_url = '^' . $this->convertToRule($old_url, '\s', '\s') . '(/)?$';
        $new_url = $this->convertToRule($new_url, '\s', '%20') . '$1';

        $rewrite_rule->setArguments(compact('old_url', 'new_url', 'type'));

        return $rewrite_rule;
    }

    /**
     * Remove the current domain from the URL
     *
     * @param string $url
     *
     * @return mixed
     */
    private function removeDomain($url)
    {

        $app = $this->getContainer();
        /**
         * @var \Bolt\Configuration\PathsProxy $paths
         */
        $paths = $app['paths'];

        $host = $paths->offsetGet('hosturl');

        if (!empty($host)) {
            $url = str_replace($host, '', $url);
        }

        return $url;
    }

    /**
     * Trim whitespace etc and forward slashes from the URL
     *
     * @param string $url
     *
     * @return string
     */
    private function trimUrl($url)
    {

        return trim(trim($url), '/');
    }

    /**
     * Prepend a forward slash to the URL if it doesn't have http in front
     *
     * @param string $url
     *
     * @return string
     */
    private function prependSlash($url)
    {

        if (strpos($url, 'http') !== 0 && strpos($url, '/') !== 0) {
            $url = '/' . $url;
        }

        return $url;
    }

    /**
     * Format and escape the query string parts of a URL
     *
     * @param string $url
     *
     * @return string
     */
    private function fixQueryString($url)
    {

        if (strpos($url, '?')) {
            $query_string = explode('?', $url);
            $url          = $query_string[0] . '?' . urlencode($query_string[1]);
        }

        return $url;
    }

    /**
     * Validates CSRF token and throws HttpException if not.
     *
     * @param string|null $value The token value or null to use "bolt_csrf_token" parameter from request.
     * @param string      $id    The token ID.
     *
     * @throws HttpExceptionInterface
     */
    protected function validateCsrfToken($value = null, $id = 'bolt')
    {

        $app = $this->getContainer();

        if (!$this->isCsrfTokenValid($value, $id)) {
            //$this->app['logger.flash']->warning('The security token was incorrect. Please try again.');
            $app->abort(Response::HTTP_BAD_REQUEST, Translator::__('general.phrase.something-went-wrong'));
        }
    }

    /**
     * Check if csrf token is valid.
     *
     * @param string|null $value The token value or null to use "bolt_csrf_token" parameter from request.
     * @param string      $id    The token ID.
     *
     * @return bool
     */
    protected function isCsrfTokenValid($value = null, $id = 'bolt')
    {

        $app   = $this->getContainer();
        $token = new CsrfToken($id, $value ?: $app['request_stack']->getCurrentRequest()
                                                                   ->get('bolt_csrf_token'));

        return $app['csrf']->isTokenValid($token);
    }

    /**
     * Add a change log entry to track the change.
     *
     * @param string      $content_type
     * @param integer     $id
     * @param array       $new_redirects
     * @param array       $old_redirects
     * @param string|null $comment
     */
    private function logChange($content_type, $id, Array $new_redirects = [], Array $old_redirects = [], $comment = null)
    {

        $app = $this->getContainer();

        $app['logger.change']->info('Update redirects manager', [
            'action'      => 'Update',
            'contenttype' => $content_type,
            'id'          => $id,
            'new'         => $new_redirects,
            'old'         => $old_redirects,
            'comment'     => $comment,
        ]);
    }
}
