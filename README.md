# Bolt Redirects Manager

This extension adds a GUI redirects manager to Bolt's admin area. 

This extension writes to the sites `/public/.htaccess` file. 

If your install is not set up to use a `/public` folder this extension will **NOT** work for you. 

## Requirements

1. You **MUST** be running an apache server with `mod_rewrite` enabled.

1. You site's webroot **MUST** be within a `/public` folder

1. You **MUST** add `tivie/htaccess-parser` to your project root `composer.json`. For example:

```
{
    "name": "bolt/composer-install",
    "description": "Sophisticated, lightweight & simple CMS",
    "type": "project",
    "license": "MIT",
    "require": {
        "php": "^5.5.9 || ^7.0",
        "bolt/bolt": "^3.3",
        "passwordlib/passwordlib": "^1.0@beta"
        "tivie/htaccess-parser": "*"
    },
    "minimum-stability": "beta",
    "prefer-stable": true,
    "scripts": {
        "post-install-cmd": [
            "Bolt\\Composer\\ScriptHandler::installAssets"
        ],
        "post-update-cmd": [
            "Bolt\\Composer\\ScriptHandler::updateProject",
            "Bolt\\Composer\\ScriptHandler::installAssets"
        ],
        "post-create-project-cmd": [
            "Bolt\\Composer\\ScriptHandler::configureProject",
            "Bolt\\Composer\\ScriptHandler::installThemesAndFiles",
            "nut extensions:setup"
        ]
    },
    "extra": {
        "branch-alias": {
            "dev-3.3" : "3.3.x-dev"
        }
    }
}


```

## Usage

Your `/public/.htaccess` file needs to contain a `### Redirects Manager block` as in the below example. This extension will save redirects in the `### Redirects Manager block` section of the file.
                                                                                                        
```
<IfModule mod_rewrite.c>
    RewriteEngine on
    
    #RewriteRule cache/ - [F]
    
    # Some servers require the RewriteBase to be set. If so, set to the correct folder.
    #RewriteBase /

    ### Redirects Manager block

    ### END Redirects Manager block    
    
    # Bolt rewrite
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} !=/favicon.ico
    RewriteRule ^ ./index.php [L]
</IfModule>
```

*Note: There is currently a bug where this is only working if the `### Redirects Manager block` is inside another block such as `<IfModule mod_rewrite.c>` (i.e. it only works when it's a child of something else).*
