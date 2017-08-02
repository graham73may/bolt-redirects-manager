# Bolt Redirect Manager

This extension adds a GUI redirects manager to Bolt's admin area. 

This extension writes to the sites `/public/.htaccess` file. 

If your install is not setup to use a `/public` folder this extension will **NOT** work for you. 

## Requirements

You **MUST** be running an apache server with mod_rewrite enabled.

You site's webroot **MUST** be within a `/public` folder

## Usage

Your `/public/.htaccess` file needs to contain this block: 

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

This extension will save redirects in the `### Redirects Manager block` section of the file.
