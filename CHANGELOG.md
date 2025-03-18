# WSO2 Authentication Module Changelog

## 1.0.0 (2025-03-17)
Initial release with the following features:
- OAuth2 authentication with WSO2 Identity Server
- Support for both staging and production environments
- Automatic user registration
- Field mapping between WSO2 and Drupal user fields (including fiscal code and mobile phone)
- Automatic role assignment for new users
- Single sign-on (SSO) capability
- Single logout (SLO) capability
- Integration with the Drupal login form
- SPID logo support on login form
- Session validation and security checks
- SSL verification skip option for development
- Status block showing authentication information
- Environment helper for handling different environments

### Settings
- General settings (enable/disable, staging/production)
- Server settings (URL, entity ID, SSL verification)
- OAuth2 settings (client ID, client secret, redirect URI, scope)
- User settings (auto-register, SPID logo, role assignment)
- Field mappings (username, email, first name, last name, fiscal code, mobile phone)
- Advanced settings (auto-redirect, debug mode)

### Hooks
- hook_wso2_auth_userinfo_alter()
- hook_wso2_auth_post_login()
- hook_wso2_auth_authorization_url_alter()
- hook_wso2_auth_token_request_alter()
- hook_wso2_auth_userinfo_request_alter()
- hook_wso2_auth_logout_url_alter()

### Services
- wso2_auth.authentication
- wso2_auth.environment_helper

### Blocks
- WSO2 Authentication Login Block
- WSO2 Authentication Status Block
