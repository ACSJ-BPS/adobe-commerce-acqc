pipeline {
    agent any
    
    parameters {
        choice(
            name: 'ANALYSIS_TYPE',
            choices: ['quick', 'full', 'security', 'performance'],
            description: 'Type of analysis to perform'
        )
        booleanParam(
            name: 'UPDATE_BASELINE',
            defaultValue: false,
            description: 'Update quality baseline'
        )
    }
    
    environment {
        // M2-Quality configuration
        M2QUALITY_CONFIG = 'examples/configurations/ci-config.json'
        M2QUALITY_REPORTS_DIR = 'reports'
        
        // Credentials
        OPENAI_API_KEY = credentials('openai-api-key')
        SLACK_WEBHOOK = credentials('slack-webhook-url')
    }
    
    stages {
        stage('Preparation') {
            steps {
                script {
                    // Clean workspace
                    deleteDir()
                    
                    // Checkout code
                    checkout scm
                    
                    // Create reports directory
                    sh 'mkdir -p ${M2QUALITY_REPORTS_DIR}'
                    
                    // Install M2-Quality
                    sh '''
                        composer global require m2quality/m2-quality
                        export PATH="$HOME/.composer/vendor/bin:$PATH"
                        m2-quality --version
                    '''
                }
            }
        }
        
        stage('Quality Analysis') {
            parallel {
                stage('Code Quality') {
                    when {
                        anyOf {
                            params.ANALYSIS_TYPE == 'quick'
                            params.ANALYSIS_TYPE == 'full'
                        }
                    }
                    steps {
                        script {
                            def configFile = params.ANALYSIS_TYPE == 'full' ? 
                                'examples/configurations/production-config.json' : 
                                'examples/configurations/ci-config.json'
                                
                            sh """
                                export PATH="\$HOME/.composer/vendor/bin:\$PATH"
                                m2-quality scan . \\
                                    --config=${configFile} \\
                                    --format=json \\
                                    --output=${M2QUALITY_REPORTS_DIR}/quality-analysis.json \\
                                    --report-html=${M2QUALITY_REPORTS_DIR}/quality-report.html \\
                                    --baseline=baseline.json
                            """
                        }
                    }
                    post {
                        always {
                            // Archive quality reports
                            archiveArtifacts artifacts: "${M2QUALITY_REPORTS_DIR}/quality-*", allowEmptyArchive: true
                            
                            // Publish HTML report
                            publishHTML([
                                allowMissing: false,
                                alwaysLinkToLastBuild: true,
                                keepAll: true,
                                reportDir: M2QUALITY_REPORTS_DIR,
                                reportFiles: 'quality-report.html',
                                reportName: 'Quality Report'
                            ])
                        }
                    }
                }
                
                stage('Security Scan') {
                    when {
                        anyOf {
                            params.ANALYSIS_TYPE == 'security'
                            params.ANALYSIS_TYPE == 'full'
                        }
                    }
                    steps {
                        sh """
                            export PATH="\$HOME/.composer/vendor/bin:\$PATH"
                            m2-quality scan . \\
                                --config=config/rules/security.yaml \\
                                --analyzers=security \\
                                --severity=warning \\
                                --format=json \\
                                --output=${M2QUALITY_REPORTS_DIR}/security-scan.json
                        """
                    }
                    post {
                        always {
                            // Process security results
                            script {
                                def securityResults = readJSON file: "${M2QUALITY_REPORTS_DIR}/security-scan.json"
                                def criticalIssues = securityResults.summary?.critical_violations ?: 0
                                
                                if (criticalIssues > 0) {
                                    currentBuild.result = 'UNSTABLE'
                                    env.SECURITY_ALERT = "true"
                                }
                            }
                        }
                    }
                }
                
                stage('Performance Analysis') {
                    when {
                        anyOf {
                            params.ANALYSIS_TYPE == 'performance'
                            params.ANALYSIS_TYPE == 'full'
                        }
                    }
                    steps {
                        sh """
                            export PATH="\$HOME/.composer/vendor/bin:\$PATH"
                            m2-quality scan . \\
                                --config=config/rules/performance.yaml \\
                                --analyzers=performance \\
                                --format=json \\
                                --output=${M2QUALITY_REPORTS_DIR}/performance-analysis.json
                        """
                        
                        // Run performance benchmarks
                        sh """
                            export PATH="\$HOME/.composer/vendor/bin:\$PATH"
                            m2-quality benchmark \\
                                --iterations=50 \\
                                --output=${M2QUALITY_REPORTS_DIR}/performance-benchmark.json
                        """
                    }
                }
            }
        }
        
        stage('AI Code Review') {
            when {
                changeRequest()
                environment name: 'OPENAI_API_KEY', value: ''
            }
            steps {
                sh """
                    export PATH="\$HOME/.composer/vendor/bin:\$PATH"
                    m2-quality ask \\
                        "Review this pull request for code quality, security, and performance issues" \\
                        --include-code \\
                        --role=architect \\
                        --format=markdown > ${M2QUALITY_REPORTS_DIR}/ai-code-review.md
                """
            }
            post {
                always {
                    archiveArtifacts artifacts: "${M2QUALITY_REPORTS_DIR}/ai-code-review.md", allowEmptyArchive: true
                }
            }
        }
        
        stage('Quality Gate') {
            steps {
                script {
                    // Read quality analysis results
                    def qualityResults = readJSON file: "${M2QUALITY_REPORTS_DIR}/quality-analysis.json"
                    def qualityScore = qualityResults.summary?.quality_score ?: 0
                    def criticalViolations = qualityResults.summary?.critical_violations ?: 0
                    def errorViolations = qualityResults.summary?.error_violations ?: 0
                    
                    echo "Quality Score: ${qualityScore}/10"
                    echo "Critical Violations: ${criticalViolations}"
                    echo "Error Violations: ${errorViolations}"
                    
                    // Set quality gate thresholds
                    def qualityThreshold = 7.0
                    def maxCriticalViolations = 0
                    def maxErrorViolations = 5
                    
                    // Check quality gate
                    if (qualityScore < qualityThreshold) {
                        error("Quality gate failed: Score ${qualityScore} < ${qualityThreshold}")
                    }
                    
                    if (criticalViolations > maxCriticalViolations) {
                        error("Quality gate failed: ${criticalViolations} critical violations > ${maxCriticalViolations}")
                    }
                    
                    if (errorViolations > maxErrorViolations) {
                        currentBuild.result = 'UNSTABLE'
                        echo "Warning: ${errorViolations} error violations > ${maxErrorViolations}"
                    }
                    
                    // Set environment variables for notifications
                    env.QUALITY_SCORE = qualityScore.toString()
                    env.CRITICAL_VIOLATIONS = criticalViolations.toString()
                    env.ERROR_VIOLATIONS = errorViolations.toString()
                }
            }
        }
        
        stage('Update Baseline') {
            when {
                allOf {
                    branch 'main'
                    params.UPDATE_BASELINE == true
                }
            }
            steps {
                sh """
                    export PATH="\$HOME/.composer/vendor/bin:\$PATH"
                    m2-quality baseline update production \\
                        --config=examples/configurations/production-config.json
                """
                
                // Commit updated baseline
                sh '''
                    git config user.email "jenkins@company.com"
                    git config user.name "Jenkins CI"
                    git add baseline.json
                    git commit -m "Update quality baseline [skip ci]" || true
                    git push origin main || true
                '''
            }
        }
    }
    
    post {
        always {
            // Archive all reports
            archiveArtifacts artifacts: "${M2QUALITY_REPORTS_DIR}/**/*", allowEmptyArchive: true
            
            // Publish test results if available
            script {
                if (fileExists("${M2QUALITY_REPORTS_DIR}/junit-results.xml")) {
                    publishTestResults testResultsPattern: "${M2QUALITY_REPORTS_DIR}/junit-results.xml"
                }
            }
            
            // Generate quality trend data
            script {
                def qualityData = [
                    timestamp: new Date().format('yyyy-MM-dd HH:mm:ss'),
                    build: env.BUILD_NUMBER,
                    branch: env.BRANCH_NAME,
                    quality_score: env.QUALITY_SCORE ?: '0',
                    critical_violations: env.CRITICAL_VIOLATIONS ?: '0',
                    error_violations: env.ERROR_VIOLATIONS ?: '0'
                ]
                
                writeJSON file: "${M2QUALITY_REPORTS_DIR}/quality-trend.json", json: qualityData
            }
        }
        
        success {
            script {
                // Send success notification for main branch
                if (env.BRANCH_NAME == 'main') {
                    slackSend(
                        channel: '#quality',
                        color: 'good',
                        message: """
                            ✅ Quality analysis passed for ${env.JOB_NAME} #${env.BUILD_NUMBER}
                            Quality Score: ${env.QUALITY_SCORE}/10
                            Critical Issues: ${env.CRITICAL_VIOLATIONS}
                            Build: ${env.BUILD_URL}
                        """.stripIndent(),
                        tokenCredentialId: 'slack-token'
                    )
                }
            }
        }
        
        failure {
            script {
                slackSend(
                    channel: '#quality',
                    color: 'danger',
                    message: """
                        🚨 Quality analysis failed for ${env.JOB_NAME} #${env.BUILD_NUMBER}
                        Branch: ${env.BRANCH_NAME}
                        Build: ${env.BUILD_URL}
                        
                        Please check the quality report for details.
                    """.stripIndent(),
                    tokenCredentialId: 'slack-token'
                )
            }
            
            // Send email notification for critical failures
            script {
                if (env.SECURITY_ALERT == 'true') {
                    emailext(
                        subject: "🚨 Security Issues Detected - ${env.JOB_NAME} #${env.BUILD_NUMBER}",
                        body: """
                            Critical security issues were detected in the code analysis.
                            
                            Build: ${env.BUILD_URL}
                            Branch: ${env.BRANCH_NAME}
                            
                            Please review the security scan report immediately.
                        """,
                        to: '${SECURITY_TEAM_EMAIL}',
                        attachLog: true
                    )
                }
            }
        }
        
        unstable {
            slackSend(
                channel: '#quality',
                color: 'warning',
                message: """
                    ⚠️ Quality analysis completed with warnings for ${env.JOB_NAME} #${env.BUILD_NUMBER}
                    Quality Score: ${env.QUALITY_SCORE}/10
                    Error Violations: ${env.ERROR_VIOLATIONS}
                    Build: ${env.BUILD_URL}
                """.stripIndent(),
                tokenCredentialId: 'slack-token'
            )
        }
        
        cleanup {
            // Clean up workspace
            deleteDir()
        }
    }
} 