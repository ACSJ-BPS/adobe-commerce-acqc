<?php

declare(strict_types=1);

namespace ACQC\Core\Config;

use ACQC\Core\Config\ConfigManager;

/**
 * Role-Based Quality Configuration
 * 
 * Defines quality checklists and rules for different developer roles:
 * - Junior Developer
 * - Senior Developer  
 * - Architect
 */
class RoleConfig
{
    private const ROLES = [
        'junior-developer' => [
            'name' => 'Junior Developer',
            'level' => 1,
            'description' => 'Entry-level developer with focus on code quality basics and Magento 2 fundamentals'
        ],
        'senior-developer' => [
            'name' => 'Senior Developer',
            'level' => 2,
            'description' => 'Experienced developer with advanced patterns, performance optimization, and security'
        ],
        'architect' => [
            'name' => 'Architect',
            'level' => 3,
            'description' => 'System architect with focus on scalability, technical debt, and enterprise patterns'
        ]
    ];

    private const QUALITY_CHECKLISTS = [
        'junior-developer' => [
            'code_quality_basics' => [
                'name' => 'Code Quality Basics',
                'items' => [
                    'code_quality_basics' => [
                        'name' => 'Code Quality Basics',
                        'checks' => [
                            'syntax_structure' => [
                                'name' => 'Syntax & Structure',
                                'checks' => [
                                    'no_php_syntax_errors' => [
                                        'name' => 'No PHP syntax errors',
                                        'severity' => 'error',
                                        'tools' => ['phpcs', 'phpstan'],
                                        'description' => 'Ensure no PHP syntax errors in code'
                                    ],
                                    'proper_indentation' => [
                                        'name' => 'Proper indentation and formatting',
                                        'severity' => 'warning',
                                        'tools' => ['phpcs'],
                                        'description' => 'Follow PSR-12 indentation standards'
                                    ],
                                    'naming_conventions' => [
                                        'name' => 'Consistent naming conventions',
                                        'severity' => 'warning',
                                        'tools' => ['phpcs', 'phpstan'],
                                        'description' => 'Follow PSR-12 naming conventions'
                                    ],
                                    'psr12_compliance' => [
                                        'name' => 'Basic PSR-12 compliance',
                                        'severity' => 'warning',
                                        'tools' => ['phpcs'],
                                        'description' => 'Follow PSR-12 coding standards'
                                    ]
                                ]
                            ],
                            'magento2_fundamentals' => [
                                'name' => 'Magento 2 Fundamentals',
                                'checks' => [
                                    'module_structure' => [
                                        'name' => 'Proper module structure (registration.php, module.xml)',
                                        'severity' => 'error',
                                        'tools' => ['magento-specific'],
                                        'description' => 'Ensure proper Magento 2 module structure'
                                    ],
                                    'namespace_usage' => [
                                        'name' => 'Correct namespace usage',
                                        'severity' => 'error',
                                        'tools' => ['magento-specific'],
                                        'description' => 'Follow Magento 2 namespace conventions'
                                    ],
                                    'dependency_injection' => [
                                        'name' => 'Basic dependency injection usage',
                                        'severity' => 'warning',
                                        'tools' => ['magento-specific'],
                                        'description' => 'Use dependency injection instead of ObjectManager'
                                    ],
                                    'magento_apis' => [
                                        'name' => 'Proper use of Magento APIs',
                                        'severity' => 'warning',
                                        'tools' => ['magento-specific'],
                                        'description' => 'Use proper Magento 2 APIs and service contracts'
                                    ]
                                ]
                            ],
                            'security_basics' => [
                                'name' => 'Security Basics',
                                'checks' => [
                                    'input_validation' => [
                                        'name' => 'Input validation on user data',
                                        'severity' => 'error',
                                        'tools' => ['security'],
                                        'description' => 'Validate all user inputs'
                                    ],
                                    'template_escaping' => [
                                        'name' => 'Proper escaping in templates',
                                        'severity' => 'error',
                                        'tools' => ['security'],
                                        'description' => 'Escape output in templates'
                                    ],
                                    'no_hardcoded_credentials' => [
                                        'name' => 'No hardcoded credentials',
                                        'severity' => 'critical',
                                        'tools' => ['security'],
                                        'description' => 'Never hardcode credentials in code'
                                    ],
                                    'xss_prevention' => [
                                        'name' => 'Basic XSS prevention',
                                        'severity' => 'error',
                                        'tools' => ['security'],
                                        'description' => 'Prevent cross-site scripting attacks'
                                    ]
                                ]
                            ],
                            'performance_awareness' => [
                                'name' => 'Performance Awareness',
                                'checks' => [
                                    'no_n_plus_one' => [
                                        'name' => 'No obvious N+1 query patterns',
                                        'severity' => 'warning',
                                        'tools' => ['performance'],
                                        'description' => 'Avoid N+1 query problems'
                                    ],
                                    'collection_usage' => [
                                        'name' => 'Proper collection usage',
                                        'severity' => 'warning',
                                        'tools' => ['magento-specific'],
                                        'description' => 'Use collections efficiently'
                                    ],
                                    'caching_awareness' => [
                                        'name' => 'Basic caching awareness',
                                        'severity' => 'info',
                                        'tools' => ['performance'],
                                        'description' => 'Understand basic caching concepts'
                                    ],
                                    'no_infinite_loops' => [
                                        'name' => 'No infinite loops or recursion',
                                        'severity' => 'error',
                                        'tools' => ['performance'],
                                        'description' => 'Avoid infinite loops and excessive recursion'
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'frontend_basics' => [
                        'name' => 'Frontend Basics',
                        'items' => [
                            'html_css' => [
                                'name' => 'HTML/CSS',
                                'checks' => [
                                    'valid_html' => [
                                        'name' => 'Valid HTML structure',
                                        'severity' => 'error',
                                        'tools' => ['accessibility'],
                                        'description' => 'Ensure valid HTML markup'
                                    ],
                                    'responsive_design' => [
                                        'name' => 'Responsive design basics',
                                        'severity' => 'warning',
                                        'tools' => ['accessibility'],
                                        'description' => 'Implement basic responsive design'
                                    ],
                                    'css_organization' => [
                                        'name' => 'Proper CSS organization',
                                        'severity' => 'info',
                                        'tools' => ['frontend-performance'],
                                        'description' => 'Organize CSS properly'
                                    ],
                                    'basic_accessibility' => [
                                        'name' => 'Basic accessibility (alt tags, labels)',
                                        'severity' => 'warning',
                                        'tools' => ['accessibility'],
                                        'description' => 'Implement basic accessibility features'
                                    ]
                                ]
                            ],
                            'javascript_react' => [
                                'name' => 'JavaScript/React',
                                'checks' => [
                                    'no_console_errors' => [
                                        'name' => 'No console errors',
                                        'severity' => 'error',
                                        'tools' => ['react'],
                                        'description' => 'Ensure no JavaScript console errors'
                                    ],
                                    'react_patterns' => [
                                        'name' => 'Basic React patterns',
                                        'severity' => 'warning',
                                        'tools' => ['react'],
                                        'description' => 'Follow basic React patterns'
                                    ],
                                    'event_handling' => [
                                        'name' => 'Proper event handling',
                                        'severity' => 'warning',
                                        'tools' => ['react'],
                                        'description' => 'Handle events properly'
                                    ],
                                    'component_structure' => [
                                        'name' => 'Component structure',
                                        'severity' => 'info',
                                        'tools' => ['react'],
                                        'description' => 'Organize components properly'
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'tools' => [
                        'phpcs' => 'PHP CodeSniffer with Magento 2 standards',
                        'phpstan' => 'PHPStan level 1-3',
                        'eslint' => 'ESLint for JavaScript',
                        'browser_tools' => 'Browser developer tools'
                    ]
                ],
                'frontend_basics' => [
                    'name' => 'Frontend Basics',
                    'items' => [
                        'html_css' => [
                            'name' => 'HTML/CSS',
                            'checks' => [
                                'valid_html' => [
                                    'name' => 'Valid HTML structure',
                                    'severity' => 'error',
                                    'tools' => ['accessibility'],
                                    'description' => 'Ensure valid HTML markup'
                                ],
                                'responsive_design' => [
                                    'name' => 'Responsive design basics',
                                    'severity' => 'warning',
                                    'tools' => ['accessibility'],
                                    'description' => 'Implement basic responsive design'
                                ],
                                'css_organization' => [
                                    'name' => 'Proper CSS organization',
                                    'severity' => 'info',
                                    'tools' => ['frontend-performance'],
                                    'description' => 'Organize CSS properly'
                                ],
                                'basic_accessibility' => [
                                    'name' => 'Basic accessibility (alt tags, labels)',
                                    'severity' => 'warning',
                                    'tools' => ['accessibility'],
                                    'description' => 'Implement basic accessibility features'
                                ]
                            ]
                        ],
                        'javascript_react' => [
                            'name' => 'JavaScript/React',
                            'checks' => [
                                'no_console_errors' => [
                                    'name' => 'No console errors',
                                    'severity' => 'error',
                                    'tools' => ['react'],
                                    'description' => 'Ensure no JavaScript console errors'
                                ],
                                'react_patterns' => [
                                    'name' => 'Basic React patterns',
                                    'severity' => 'warning',
                                    'tools' => ['react'],
                                    'description' => 'Follow basic React patterns'
                                ],
                                'event_handling' => [
                                    'name' => 'Proper event handling',
                                    'severity' => 'warning',
                                    'tools' => ['react'],
                                    'description' => 'Handle events properly'
                                ],
                                'component_structure' => [
                                    'name' => 'Component structure',
                                    'severity' => 'info',
                                    'tools' => ['react'],
                                    'description' => 'Organize components properly'
                                ]
                            ]
                        ]
                    ]
                ],
                'tools' => [
                    'phpcs' => 'PHP CodeSniffer with Magento 2 standards',
                    'phpstan' => 'PHPStan level 1-3',
                    'eslint' => 'ESLint for JavaScript',
                    'browser_tools' => 'Browser developer tools'
                ]
            ],
            'senior-developer' => [
                'advanced_code_quality' => [
                    'name' => 'Advanced Code Quality',
                    'items' => [
                        'design_patterns' => [
                            'name' => 'Design Patterns',
                            'checks' => [
                                'proper_patterns' => [
                                    'name' => 'Proper use of design patterns',
                                    'severity' => 'warning',
                                    'tools' => ['phpstan'],
                                    'description' => 'Implement appropriate design patterns'
                                ],
                                'solid_principles' => [
                                    'name' => 'SOLID principles adherence',
                                    'severity' => 'warning',
                                    'tools' => ['phpstan'],
                                    'description' => 'Follow SOLID principles'
                                ],
                                'clean_architecture' => [
                                    'name' => 'Clean architecture principles',
                                    'severity' => 'info',
                                    'tools' => ['phpstan'],
                                    'description' => 'Apply clean architecture principles'
                                ],
                                'abstraction_levels' => [
                                    'name' => 'Proper abstraction levels',
                                    'severity' => 'warning',
                                    'tools' => ['phpstan'],
                                    'description' => 'Maintain proper abstraction levels'
                                ]
                            ]
                        ],
                        'magento2_advanced' => [
                            'name' => 'Magento 2 Advanced',
                            'checks' => [
                                'efficient_plugins' => [
                                    'name' => 'Efficient plugin usage',
                                    'severity' => 'warning',
                                    'tools' => ['magento-specific'],
                                    'description' => 'Use plugins efficiently'
                                ],
                                'observer_implementation' => [
                                    'name' => 'Proper observer implementation',
                                    'severity' => 'warning',
                                    'tools' => ['magento-specific'],
                                    'description' => 'Implement observers properly'
                                ],
                                'advanced_di' => [
                                    'name' => 'Advanced DI configuration',
                                    'severity' => 'info',
                                    'tools' => ['magento-specific'],
                                    'description' => 'Use advanced dependency injection'
                                ],
                                'custom_api' => [
                                    'name' => 'Custom API development',
                                    'severity' => 'warning',
                                    'tools' => ['magento-specific'],
                                    'description' => 'Develop custom APIs properly'
                                ],
                                'extension_attributes' => [
                                    'name' => 'Extension attributes usage',
                                    'severity' => 'info',
                                    'tools' => ['magento-specific'],
                                    'description' => 'Use extension attributes properly'
                                ]
                            ]
                        ],
                        'performance_optimization' => [
                            'name' => 'Performance Optimization',
                            'checks' => [
                                'query_optimization' => [
                                    'name' => 'Database query optimization',
                                    'severity' => 'warning',
                                    'tools' => ['performance'],
                                    'description' => 'Optimize database queries'
                                ],
                                'indexing_strategies' => [
                                    'name' => 'Proper indexing strategies',
                                    'severity' => 'info',
                                    'tools' => ['performance'],
                                    'description' => 'Implement proper indexing'
                                ],
                                'caching_implementation' => [
                                    'name' => 'Caching implementation',
                                    'severity' => 'warning',
                                    'tools' => ['performance'],
                                    'description' => 'Implement proper caching'
                                ],
                                'memory_optimization' => [
                                    'name' => 'Memory usage optimization',
                                    'severity' => 'warning',
                                    'tools' => ['performance'],
                                    'description' => 'Optimize memory usage'
                                ],
                                'lazy_loading' => [
                                    'name' => 'Lazy loading patterns',
                                    'severity' => 'info',
                                    'tools' => ['performance'],
                                    'description' => 'Implement lazy loading'
                                ]
                            ]
                        ],
                        'security_advanced' => [
                            'name' => 'Security Advanced',
                            'checks' => [
                                'csrf_protection' => [
                                    'name' => 'CSRF protection',
                                    'severity' => 'error',
                                    'tools' => ['security'],
                                    'description' => 'Implement CSRF protection'
                                ],
                                'sql_injection_prevention' => [
                                    'name' => 'SQL injection prevention',
                                    'severity' => 'critical',
                                    'tools' => ['security'],
                                    'description' => 'Prevent SQL injection attacks'
                                ],
                                'authentication_authorization' => [
                                    'name' => 'Proper authentication/authorization',
                                    'severity' => 'error',
                                    'tools' => ['security'],
                                    'description' => 'Implement proper auth'
                                ],
                                'data_encryption' => [
                                    'name' => 'Data encryption where needed',
                                    'severity' => 'warning',
                                    'tools' => ['security'],
                                    'description' => 'Encrypt sensitive data'
                                ],
                                'security_headers' => [
                                    'name' => 'Security headers implementation',
                                    'severity' => 'warning',
                                    'tools' => ['security'],
                                    'description' => 'Implement security headers'
                                ]
                            ]
                        ]
                    ]
                ],
                'frontend_advanced' => [
                    'name' => 'Frontend Advanced',
                    'items' => [
                        'react_pwa' => [
                            'name' => 'React/PWA',
                            'checks' => [
                                'advanced_react' => [
                                    'name' => 'Advanced React patterns (hooks, context)',
                                    'severity' => 'info',
                                    'tools' => ['react'],
                                    'description' => 'Use advanced React patterns'
                                ],
                                'state_management' => [
                                    'name' => 'State management (Redux/Context)',
                                    'severity' => 'info',
                                    'tools' => ['react'],
                                    'description' => 'Implement proper state management'
                                ],
                                'performance_optimization' => [
                                    'name' => 'Performance optimization',
                                    'severity' => 'warning',
                                    'tools' => ['frontend-performance'],
                                    'description' => 'Optimize frontend performance'
                                ],
                                'bundle_optimization' => [
                                    'name' => 'Bundle size optimization',
                                    'severity' => 'warning',
                                    'tools' => ['frontend-performance'],
                                    'description' => 'Optimize bundle sizes'
                                ],
                                'pwa_features' => [
                                    'name' => 'PWA features implementation',
                                    'severity' => 'info',
                                    'tools' => ['pwa'],
                                    'description' => 'Implement PWA features'
                                ]
                            ]
                        ],
                        'seo_analytics' => [
                            'name' => 'SEO & Analytics',
                            'checks' => [
                                'meta_tags' => [
                                    'name' => 'Meta tags optimization',
                                    'severity' => 'warning',
                                    'tools' => ['seo'],
                                    'description' => 'Optimize meta tags'
                                ],
                                'structured_data' => [
                                    'name' => 'Structured data implementation',
                                    'severity' => 'info',
                                    'tools' => ['seo'],
                                    'description' => 'Implement structured data'
                                ],
                                'core_web_vitals' => [
                                    'name' => 'Core Web Vitals optimization',
                                    'severity' => 'warning',
                                    'tools' => ['frontend-performance'],
                                    'description' => 'Optimize Core Web Vitals'
                                ],
                                'analytics_tracking' => [
                                    'name' => 'Analytics tracking',
                                    'severity' => 'info',
                                    'tools' => ['seo'],
                                    'description' => 'Implement analytics tracking'
                                ],
                                'search_optimization' => [
                                    'name' => 'Search engine optimization',
                                    'severity' => 'warning',
                                    'tools' => ['seo'],
                                    'description' => 'Optimize for search engines'
                                ]
                            ]
                        ]
                    ]
                ],
                'tools' => [
                    'phpstan' => 'PHPStan level 4-6',
                    'phpmd' => 'PHPMD with custom rules',
                    'sonarqube' => 'SonarQube integration',
                    'performance_profiling' => 'Performance profiling tools',
                    'security_scanning' => 'Security scanning tools'
                ]
            ],
            'architect' => [
                'system_architecture' => [
                    'name' => 'System Architecture',
                    'items' => [
                        'scalability_design' => [
                            'name' => 'Scalability Design',
                            'checks' => [
                                'horizontal_scaling' => [
                                    'name' => 'Horizontal scaling considerations',
                                    'severity' => 'info',
                                    'tools' => ['performance'],
                                    'description' => 'Design for horizontal scaling'
                                ],
                                'load_balancing' => [
                                    'name' => 'Load balancing strategies',
                                    'severity' => 'info',
                                    'tools' => ['performance'],
                                    'description' => 'Implement load balancing'
                                ],
                                'database_sharding' => [
                                    'name' => 'Database sharding/partitioning',
                                    'severity' => 'info',
                                    'tools' => ['performance'],
                                    'description' => 'Design database sharding'
                                ],
                                'microservices' => [
                                    'name' => 'Microservices architecture',
                                    'severity' => 'info',
                                    'tools' => ['performance'],
                                    'description' => 'Design microservices architecture'
                                ],
                                'api_design' => [
                                    'name' => 'API design and versioning',
                                    'severity' => 'warning',
                                    'tools' => ['magento-specific'],
                                    'description' => 'Design APIs with versioning'
                                ]
                            ]
                        ],
                        'technical_debt' => [
                            'name' => 'Technical Debt Management',
                            'checks' => [
                                'complexity_analysis' => [
                                    'name' => 'Code complexity analysis',
                                    'severity' => 'warning',
                                    'tools' => ['phpstan'],
                                    'description' => 'Analyze code complexity'
                                ],
                                'dependency_management' => [
                                    'name' => 'Dependency management',
                                    'severity' => 'warning',
                                    'tools' => ['phpstan'],
                                    'description' => 'Manage dependencies properly'
                                ],
                                'upgrade_planning' => [
                                    'name' => 'Upgrade path planning',
                                    'severity' => 'info',
                                    'tools' => ['magento-specific'],
                                    'description' => 'Plan upgrade paths'
                                ],
                                'legacy_refactoring' => [
                                    'name' => 'Legacy code refactoring',
                                    'severity' => 'info',
                                    'tools' => ['phpstan'],
                                    'description' => 'Refactor legacy code'
                                ],
                                'documentation' => [
                                    'name' => 'Documentation completeness',
                                    'severity' => 'warning',
                                    'tools' => ['phpstan'],
                                    'description' => 'Maintain complete documentation'
                                ]
                            ]
                        ],
                        'integration_architecture' => [
                            'name' => 'Integration Architecture',
                            'checks' => [
                                'third_party_integration' => [
                                    'name' => 'Third-party integration patterns',
                                    'severity' => 'info',
                                    'tools' => ['magento-specific'],
                                    'description' => 'Design third-party integrations'
                                ],
                                'event_driven' => [
                                    'name' => 'Event-driven architecture',
                                    'severity' => 'info',
                                    'tools' => ['magento-specific'],
                                    'description' => 'Implement event-driven architecture'
                                ],
                                'message_queues' => [
                                    'name' => 'Message queue implementation',
                                    'severity' => 'info',
                                    'tools' => ['performance'],
                                    'description' => 'Implement message queues'
                                ],
                                'api_gateway' => [
                                    'name' => 'API gateway patterns',
                                    'severity' => 'info',
                                    'tools' => ['magento-specific'],
                                    'description' => 'Design API gateways'
                                ],
                                'service_mesh' => [
                                    'name' => 'Service mesh considerations',
                                    'severity' => 'info',
                                    'tools' => ['performance'],
                                    'description' => 'Consider service mesh patterns'
                                ]
                            ]
                        ]
                    ]
                ],
                'advanced_performance' => [
                    'name' => 'Advanced Performance',
                    'items' => [
                        'system_performance' => [
                            'name' => 'System Performance',
                            'checks' => [
                                'application_profiling' => [
                                    'name' => 'Application profiling',
                                    'severity' => 'info',
                                    'tools' => ['performance'],
                                    'description' => 'Profile application performance'
                                ],
                                'database_tuning' => [
                                    'name' => 'Database performance tuning',
                                    'severity' => 'warning',
                                    'tools' => ['performance'],
                                    'description' => 'Tune database performance'
                                ],
                                'caching_strategies' => [
                                    'name' => 'Caching strategies (Redis, Varnish)',
                                    'severity' => 'info',
                                    'tools' => ['performance'],
                                    'description' => 'Implement caching strategies'
                                ],
                                'cdn_implementation' => [
                                    'name' => 'CDN implementation',
                                    'severity' => 'info',
                                    'tools' => ['frontend-performance'],
                                    'description' => 'Implement CDN'
                                ],
                                'resource_optimization' => [
                                    'name' => 'Resource optimization',
                                    'severity' => 'warning',
                                    'tools' => ['performance'],
                                    'description' => 'Optimize system resources'
                                ]
                            ]
                        ],
                        'monitoring_observability' => [
                            'name' => 'Monitoring & Observability',
                            'checks' => [
                                'application_monitoring' => [
                                    'name' => 'Application monitoring',
                                    'severity' => 'info',
                                    'tools' => ['performance'],
                                    'description' => 'Monitor application health'
                                ],
                                'error_tracking' => [
                                    'name' => 'Error tracking and alerting',
                                    'severity' => 'warning',
                                    'tools' => ['performance'],
                                    'description' => 'Track errors and alert'
                                ],
                                'performance_metrics' => [
                                    'name' => 'Performance metrics',
                                    'severity' => 'info',
                                    'tools' => ['performance'],
                                    'description' => 'Collect performance metrics'
                                ],
                                'log_aggregation' => [
                                    'name' => 'Log aggregation',
                                    'severity' => 'info',
                                    'tools' => ['performance'],
                                    'description' => 'Aggregate logs properly'
                                ],
                                'health_checks' => [
                                    'name' => 'Health check implementation',
                                    'severity' => 'warning',
                                    'tools' => ['performance'],
                                    'description' => 'Implement health checks'
                                ]
                            ]
                        ]
                    ]
                ],
                'security_architecture' => [
                    'name' => 'Security Architecture',
                    'items' => [
                        'enterprise_security' => [
                            'name' => 'Enterprise Security',
                            'checks' => [
                                'security_architecture' => [
                                    'name' => 'Security architecture review',
                                    'severity' => 'critical',
                                    'tools' => ['security'],
                                    'description' => 'Review security architecture'
                                ],
                                'threat_modeling' => [
                                    'name' => 'Threat modeling',
                                    'severity' => 'warning',
                                    'tools' => ['security'],
                                    'description' => 'Perform threat modeling'
                                ],
                                'compliance_requirements' => [
                                    'name' => 'Compliance requirements (PCI, GDPR)',
                                    'severity' => 'critical',
                                    'tools' => ['security'],
                                    'description' => 'Meet compliance requirements'
                                ],
                                'security_testing' => [
                                    'name' => 'Security testing automation',
                                    'severity' => 'warning',
                                    'tools' => ['security'],
                                    'description' => 'Automate security testing'
                                ],
                                'incident_response' => [
                                    'name' => 'Incident response planning',
                                    'severity' => 'warning',
                                    'tools' => ['security'],
                                    'description' => 'Plan incident response'
                                ]
                            ]
                        ]
                    ]
                ],
                'tools' => [
                    'phpstan' => 'PHPStan level 7-8',
                    'custom_static_analysis' => 'Custom static analysis tools',
                    'architecture_analysis' => 'Architecture analysis tools',
                    'performance_monitoring' => 'Performance monitoring (New Relic, DataDog)',
                    'security_scanning' => 'Security scanning (OWASP ZAP, Snyk)'
                ]
            ]
        ]
    ];

    private ConfigManager $configManager;

    public function __construct(?ConfigManager $configManager = null)
    {
        $this->configManager = $configManager ?? new ConfigManager();
    }

    /**
     * Get available roles
     */
    public function getRoles(): array
    {
        return self::ROLES;
    }

    /**
     * Get role configuration
     */
    public function getRole(string $roleId): ?array
    {
        return self::ROLES[$roleId] ?? null;
    }

    /**
     * Get quality checklist for a role
     */
    public function getQualityChecklist(string $roleId): ?array
    {
        return self::QUALITY_CHECKLISTS[$roleId] ?? null;
    }

    /**
     * Get specific checklist item
     */
    public function getChecklistItem(string $roleId, string $category, string $item): ?array
    {
        $checklist = $this->getQualityChecklist($roleId);
        return $checklist[$category]['items'][$item] ?? null;
    }

    /**
     * Get tools for a role
     */
    public function getRoleTools(string $roleId): array
    {
        $checklist = $this->getQualityChecklist($roleId);
        return $checklist['tools'] ?? [];
    }

    /**
     * Validate role
     */
    public function isValidRole(string $roleId): bool
    {
        return isset(self::ROLES[$roleId]);
    }

    /**
     * Get role level
     */
    public function getRoleLevel(string $roleId): int
    {
        return self::ROLES[$roleId]['level'] ?? 0;
    }

    /**
     * Get all checks for a role
     */
    public function getAllChecks(string $roleId): array
    {
        $checklist = $this->getQualityChecklist($roleId);
        $checks = [];

        foreach ($checklist as $categoryKey => $category) {
            if ($categoryKey === 'tools') {
                continue;
            }

            if (!isset($category['items']) || !is_array($category['items'])) {
                continue;
            }

            foreach ($category['items'] as $itemKey => $item) {
                if (!isset($item['checks']) || !is_array($item['checks'])) {
                    continue;
                }
                
                foreach ($item['checks'] as $checkKey => $check) {
                    $checks[$checkKey] = array_merge($check, [
                        'category' => $categoryKey,
                        'item' => $itemKey,
                        'role' => $roleId
                    ]);
                }
            }
        }

        return $checks;
    }

    /**
     * Get checks by severity for a role
     */
    public function getChecksBySeverity(string $roleId, string $severity): array
    {
        $allChecks = $this->getAllChecks($roleId);
        return array_filter($allChecks, function($check) use ($severity) {
            return $check['severity'] === $severity;
        });
    }

    /**
     * Get checks by tool for a role
     */
    public function getChecksByTool(string $roleId, string $tool): array
    {
        $allChecks = $this->getAllChecks($roleId);
        return array_filter($allChecks, function($check) use ($tool) {
            return in_array($tool, $check['tools']);
        });
    }
} 