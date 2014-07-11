<?php

namespace theses\plugin\twitter;

use theses\Events;
use theses\Theses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Twitter Plugin
 *
 * Exposes the following services:
 *
 * - twitter.app: The Twitter application. Can be used to post tweets and retrieve timelines by
 *                other plugins.
 */
class TwitterPlugin implements Plugin
{
    static function getPluginInfo()
    {
        return [
            'name' => 'Twitter',
            'homepage' => 'http://github.com/CHH/theses-core',
            'author' => [
                'name' => 'Christoph Hochstrasser',
                'homepage' => 'http://christophh.net',
                'email' => 'hello@christophh.net',
            ],
        ];
    }

    function register(Theses $core)
    {
        $settings = $core['settings_factory']('twitter', [
            'enabled' => true
        ]);

        $core->addSettingsMenuEntry(static::getPluginInfo()['name'], ['route' => 'twitter_settings']);

        $core['twitter.app'] = $core->share(function() use ($core, $settings) {
            $defaults = [
                'consumer_key' => $_SERVER['TWITTER_CONSUMER_KEY'],
                'consumer_secret' => $_SERVER['TWITTER_CONSUMER_SECRET'],
            ];

            $config = array_merge($defaults, isset($core['twitter.config']) ? $core['twitter.config'] : []);

            if ($settings->get('accessToken')) {
                $config['access_token'] = $settings->get('accessToken');
                $config['access_token_secret'] = $settings->get('accessTokenSecret');
            }

            return new \TTools\App($config);
        });

        $core->on(Events::POST_PUBLISH, function($event) use ($core, $settings) {
            if (!$settings->get('enabled')) {
                return;
            }

            $post = $event->getPost();
            $tweetTemplate = $settings->get('tweet');

            $variables = [
                'title' => $post->getTitle(),
                'url' => $core['system_settings']->get('siteUrl') . $post->getUrl()
            ];

            $vars = array_map(
                function($key) { return '{' . $key . '}'; },
                array_keys($variables)
            );

            $tweet = str_replace($vars, array_values($variables), $tweetTemplate);

            $core['twitter.app']->update($tweet);
        });

        $core['admin.engine'] = $core->share(
            $core->extend('admin.engine', function($admin) use ($core, $settings) {
                $admin['twitter.settings.form'] = $admin->protect(function($data) use ($admin) {
                    return $admin->form($data)
                        ->add('enabled', 'checkbox', ['label' => 'Update Twitter status when a post is published'])
                        ->add('tweet', 'textarea', ['attr' => ['rows' => 3]]);
                });

                $admin->get('/settings/twitter', function(Request $request) use ($admin, $core, $settings) {
                    return $admin['twig']->render('@TwitterPlugin/settings.html', [
                        'pluginInfo' => static::getPluginInfo(),
                        'twitterUser' => $core['twitter.app']->getCredentials(),
                        'settings' => $admin['twitter.settings.form']($settings->all())->getForm()->createView(),
                    ]);
                })->bind('twitter_settings');

                $admin->post('/settings/twitter/connect', function() use ($admin, $core) {
                    return $admin->redirect($core['twitter.app']->getLoginUrl(
                        'http://localhost:8001/admin/settings/twitter/finish_connect'
                    ));
                })->bind('twitter_connect');

                $admin->post('/settings/twitter/disconnect', function() use ($admin, $core, $settings) {
                    $settings->set([
                        'accessToken' => null,
                        'accessTokenSecret' => null,
                    ]);

                    $core['twitter.app']->logout();

                    return $admin->redirect($admin->path('twitter_settings'));
                })->bind('twitter_disconnect');

                $admin->match('/settings/twitter/finish_connect', function(Request $request) use ($admin, $core, $settings) {
                    $user = $core['twitter.app']->getUser();

                    $settings->set([
                        'accessToken' => $user['access_token'],
                        'accessTokenSecret' => $user['access_token_secret'],
                    ]);

                    return $admin->redirect($admin->path('twitter_settings'));
                });

                $admin->post('/settings/twitter/save', function(Request $request) use ($admin, $core, $settings) {
                    $form = $admin['twitter.settings.form']($settings->all())->getForm();
                    $form->handleRequest($request);

                    $settings->set($form->getData());

                    return $admin->redirect($admin->path('twitter_settings'));
                })->bind('twitter_settings_save');

                return $admin;
            })
        );
    }
}
