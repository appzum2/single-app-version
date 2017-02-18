App.config(function($routeProvider) {

    $routeProvider.when(BASE_URL+"/backoffice/advanced_module", {
        controller: 'BackofficeAdvancedController',
        templateUrl: BASE_URL+"/backoffice/advanced_module/template"
    }).when(BASE_URL+"/backoffice/advanced_configuration", {
        controller: 'BackofficeAdvancedConfigurationController',
        templateUrl: BASE_URL+"/backoffice/advanced_configuration/template"
    }).when(BASE_URL+"/backoffice/advanced_tools", {
        controller: 'BackofficeAdvancedToolsController',
        templateUrl: BASE_URL+"/backoffice/advanced_tools/template"
    }).when(BASE_URL+"/backoffice/advanced_cron", {
        controller: 'BackofficeAdvancedCronController',
        templateUrl: BASE_URL+"/backoffice/advanced_cron/template"
    });

}).controller("BackofficeAdvancedController", function($scope, $interval, Header, Advanced) {

    $scope.header = new Header();
    $scope.header.button.left.is_visible = false;
    $scope.header.loader_is_visible = false;
    $scope.content_loader_is_visible = true;

    Advanced.loadData().success(function(data) {
        $scope.header.title = data.title;
        $scope.header.icon = data.icon;
    }).finally(function() {
        $scope.content_loader_is_visible = false;
    });

    $scope.content_loader_is_visible = true;
    Advanced.findAll().success(function(data) {
        $scope.modules = data.modules;
        $scope.core_modules = data.core_modules;
        $scope.layouts = data.layouts;
        $scope.icons = data.icons;
    }).finally(function() {
        $scope.content_loader_is_visible = false;
    });

    $scope.moduleAction = function(module, action) {
        $scope.form_loader_is_visible = true;

        Advanced.moduleAction(module, action).success(function(data) {

            if(angular.isObject(data) && angular.isDefined(data.message)) {
                message = data.message;
                $scope.message.isError(false);
            }

            $scope.message.setText(message)
                .show()
            ;
        }).error(function(data) {
            if(angular.isObject(data) && angular.isDefined(data.message)) {
                message = data.message;
            }

            $scope.message.setText(message)
                .isError(true)
                .show()
            ;
        }).finally(function() {
            $scope.form_loader_is_visible = false;
        });
    };



}).controller("BackofficeAdvancedConfigurationController", function($http, $scope, $timeout, $interval, Label, Header, AdvancedConfiguration, FileUploader, Url) {

    $scope.header = new Header();
    $scope.header.button.left.is_visible = false;
    $scope.header.loader_is_visible = false;
    $scope.content_loader_is_visible = true;
    $scope.hostname = "";
    $scope.show_upload = false;

    AdvancedConfiguration.loadData().success(function(data) {
        $scope.header.title = data.title;
        $scope.header.icon = data.icon;
        $scope.hostname = data.hostname;
    }).finally(function() {
        $scope.content_loader_is_visible = false;
    });

    $scope.content_loader_is_visible = true;
    AdvancedConfiguration.findAll().success(function(data) {
        $scope.configs = data;
    }).finally(function() {
        $scope.content_loader_is_visible = false;
    });

    $scope.save = function() {

        $scope.form_loader_is_visible = true;

        AdvancedConfiguration.save($scope.configs).success(function(data) {

            $scope.message.onSuccess(data);

        }).error(function(data) {

            $scope.message.onError(data);

        }).finally(function() {
            $scope.form_loader_is_visible = false;

            $timeout(function() {
                location.reload();
            }, 3000);
        });
    };

    $scope.show_force = false;

    $scope.all_messages = null;

    $scope.generateSsl = function(hostname, force) {
        $scope.content_loader_is_visible = true;

        AdvancedConfiguration.save($scope.configs).success(function(data) {

            $scope.message.onSuccess(data);

            /** When setting are ok, go for SSL */
            AdvancedConfiguration.generateSsl(hostname, force).success(function(data) {

                /** Now if it's ok, it's time for Panel  */
                $scope.message.onSuccess(data);

                $scope.all_messages = data.all_messages;

                console.log("SSL Ok, time to push to panel.");

                if($scope.configs.cpanel_type.value == "plesk") {
                    /** Plesk is tricky, if you remove the old certificate, it' reloading ... */
                    $http({
                        method: 'GET',
                        url: 'backoffice/advanced_configuration/clearplesk',
                        cache: false,
                        responseType:'json'
                    }).then(function successCallback(response) {
                        // This may never occurs but well .. :)
                        $scope.message.onUnknown(response.data);
                        $scope.pollerRemovePlesk();
                    }, function errorCallback(response) {
                        $scope.message.onUnknown(response.data);
                        $scope.pollerRemovePlesk();
                    });
                } else if($scope.configs.cpanel_type.value == "self") {
                    $http({
                        method: 'GET',
                        url: 'backoffice/advanced_configuration/sendtopanel',
                        cache: false,
                        responseType:'json'
                    }).then(function successCallback(response) {
                        // This may never occurs but well .. :)
                        $scope.poller('backoffice/advanced_configuration/checkhttp');
                    }, function errorCallback(response) {
                        $scope.poller('backoffice/advanced_configuration/checkhttp');
                    });
                } else {
                    $http({
                        method: 'GET',
                        url: 'backoffice/advanced_configuration/sendtopanel',
                        cache: false,
                        responseType:'json'
                    }).then(function successCallback(response) {
                        // This may never occurs but well .. :)
                        $scope.poller('backoffice/advanced_configuration/checkssl');
                    }, function errorCallback(response) {
                        $scope.poller('backoffice/advanced_configuration/checkssl');
                    });
                }



            }).error(function(data) {

                $scope.message.onError(data);

            }).finally(function() {});


        }).error(function(data) {

            $scope.message.onError(data);

        }).finally(function() {});

        return false;
    };

    $scope.poller = function(url) {
        var times = 0;
        var poller = $interval(function() {

            /** We hit the timeout, show an error */
            if(times++ > 10) {
                times = 0;
                $interval.cancel(poller);
                poller = undefined;

                console.log("#01-Error: timeout reloading panel.");
                $scope.message.information($scope.all_messages.https_unreachable);
                $scope.content_loader_is_visible = false;
            }

            console.log("#02-Retrying: n"+times+" poll.");

            $http({
                method: 'GET',
                url: url,
                cache: false,
                responseType:'json'
            }).then(function successCallback(response) {
                /** Clear poller on success */
                $interval.cancel(poller);
                poller = undefined;

                /** Try to get HTTPS for redirect. */
                if(typeof response.data.https_url != "undefined") {
                    console.log('typeof response.data.https_url != "undefined"');
                    location = response.data.https_url+"/backoffice/advanced_configuration";
                } else {
                    console.log('location.reload()');
                    location.reload();
                }

            }, function errorCallback(response) {
                console.log("#03-Retry: not reachable yet.");
            });

            /**.Showing wait message */
            $scope.message.information($scope.all_messages.polling_reload);
        }, 3000);
    };

    $scope.pollerRemovePlesk = function() {
        var times = 0;
        var poller = $interval(function() {

            /** We hit the timeout, show an error */
            if(times++ > 10) {
                times = 0;
                $interval.cancel(poller);
                poller = undefined;

                console.log("#01-Error: timeout reloading panel.");
                $scope.message.information($scope.all_messages.https_unreachable);
                $scope.content_loader_is_visible = false;
            }

            console.log("#02-Retrying: n"+times+" poll.");

            $http({
                method: 'GET',
                url: 'backoffice/advanced_configuration/checkhttp',
                cache: false,
                responseType:'json'
            }).then(function successCallback(response) {
                /** Clear poller on success */
                $interval.cancel(poller);
                poller = undefined;

                /** Now it's ok, do the same as without plesk */
                $http({
                    method: 'GET',
                    url: 'backoffice/advanced_configuration/installplesk',
                    cache: false,
                    responseType:'json'
                }).then(function successCallback(response) {
                    // This may never occurs but well .. :)
                    if(angular.isObject(response.data) && angular.isDefined(response.data.error)) {
                        // Abort
                        $scope.message.onUnknown(response.data);
                    } else {
                        $scope.pollerInstallPlesk();
                    }
                }, function errorCallback(response) {
                    if(angular.isObject(response.data) && angular.isDefined(response.data.error)) {
                        // Abort
                        $scope.message.onUnknown(response.data);
                    } else {
                        $scope.pollerInstallPlesk();
                    }
                    $scope.pollerInstallPlesk();
                });

            }, function errorCallback(response) {
                console.log("#03-Retry: not reachable yet.");
            });

            /**.Showing wait message */
            $scope.message.information($scope.all_messages.polling_reload);
        }, 3000);
    };

    $scope.pollerInstallPlesk = function() {
        var times = 0;
        var poller = $interval(function() {

            /** We hit the timeout, show an error */
            if(times++ > 10) {
                times = 0;
                $interval.cancel(poller);
                poller = undefined;

                console.log("#01-Error: timeout reloading panel.");
                $scope.message.information($scope.all_messages.https_unreachable);
                $scope.content_loader_is_visible = false;
            }

            console.log("#02-Retrying: n"+times+" poll.");

            $http({
                method: 'GET',
                url: 'backoffice/advanced_configuration/checkhttp',
                cache: false,
                responseType:'json'
            }).then(function successCallback(response) {
                /** Clear poller on success */
                $interval.cancel(poller);
                poller = undefined;

                /** Now it's ok, do the same as without plesk */
                $http({
                    method: 'GET',
                    url: 'backoffice/advanced_configuration/sendtopanel',
                    cache: false,
                    responseType:'json'
                }).then(function successCallback(response) {
                    // This may never occurs but well .. :)
                    $scope.poller('backoffice/advanced_configuration/checkssl');
                }, function errorCallback(response) {
                    $scope.poller('backoffice/advanced_configuration/checkssl');
                });

            }, function errorCallback(response) {
                console.log("#03-Retry: not reachable yet.");
            });

            /**.Showing wait message */
            $scope.message.information($scope.all_messages.polling_reload);
        }, 3000);
    };

    $scope.form = {
        hostname: "",
        cert_path: "",
        ca_path: "",
        private_path: "",
        upload: "0"
    };

    $scope.uploaders = [
        {type : "cert_path",    uploader : "cert_path"},
        {type : "ca_path",      uploader : "ca_path"},
        {type : "private_path", uploader : "private_path"}
    ];

    for (var i = 0; i < $scope.uploaders.length; i++) {
        var code = $scope.uploaders[i].uploader;
        $scope[code] = new FileUploader({
            url: Url.get("backoffice/advanced_configuration/uploadcertificate?code="+code)
        });

        $scope[code].filters.push({
            name: 'limit',
            fn: function(item, options) {
                return this.queue.length < 1;
            }
        });

        $scope[code].onWhenAddingFileFailed = function(item, filter, options) {
            if(filter.name == "limit") {
                $scope.message.setText(Label.uploader.error.only_one_at_a_time).isError(true).show();
            }
        };

        $scope[code].onAfterAddingFile = function(item, filter, options) {
            item.upload();
        };

        $scope[code].onSuccessItem = function(fileItem, response, status, headers) {
            if(angular.isObject(response) && response.success) {

                $scope.form[response.code] = response.tmp_path;
                console.log($scope.form);

            } else {
                $scope.message.setText(Label.uploader.error.general)
                    .isError(true)
                    .show()
                ;
            }
        };

        $scope[code].onErrorItem = function(fileItem, response, status, headers) {
            $scope.message.setText(response.message)
                .isError(true)
                .show()
            ;
        };
    }

    $scope.disable_form = false;
    $scope.createCertificate = function() {
        if(!$scope.disable_form) {
            $scope.disable_form = true;

            AdvancedConfiguration.createCertificate($scope.form).success(function(data) {

                var message = Label.save.error;
                if(angular.isObject(data) && angular.isDefined(data.message)) {
                    message = data.message;
                    $scope.message.isError(false);
                } else {
                    $scope.message.isError(true);
                }
                $scope.message.setText(message)
                    .show()
                ;

                $scope.configs.certificates = data.certificates;

                $scope.form = {
                    hostname: "",
                    cert_path: "",
                    ca_path: "",
                    private_path: "",
                    upload: "0"
                };

            }).error(function(data) {

                var message = Label.save.error;
                if(angular.isObject(data) && angular.isDefined(data.message)) {
                    message = data.message;
                }

                $scope.message.setText(message)
                    .isError(true)
                    .show()
                ;

            }).finally(function() {
                $scope.form_loader_is_visible = false;
                $scope.disable_form = false;
            });
        }
    };

    $scope.removeCertificate = function(confirm, id) {
        if(window.confirm(confirm)) {
            AdvancedConfiguration.removeCertificate(id).success(function(data) {

                var message = Label.save.error;
                if(angular.isObject(data) && angular.isDefined(data.message)) {
                    message = data.message;
                    $scope.message.isError(false);
                } else {
                    $scope.message.isError(true);
                }
                $scope.message.setText(message)
                    .show()
                ;

                $scope.configs.certificates = data.certificates;

            }).error(function(data) {

                var message = Label.save.error;
                if(angular.isObject(data) && angular.isDefined(data.message)) {
                    message = data.message;
                }

                $scope.message.setText(message)
                    .isError(true)
                    .show()
                ;

            }).finally(function() {
                $scope.form_loader_is_visible = false;
            });
        }
    }



}).controller("BackofficeAdvancedToolsController", function($scope, $interval, Header, AdvancedTools) {

    $scope.header = new Header();
    $scope.header.button.left.is_visible = false;
    $scope.header.loader_is_visible = false;
    $scope.content_loader_is_visible = true;

    AdvancedTools.loadData().success(function(data) {
        $scope.header.title = data.title;
        $scope.header.icon = data.icon;
    }).finally(function() {
        $scope.content_loader_is_visible = false;
    });

    $scope.content_loader_is_visible = true;

    $scope.test_integrity = function() {
        $scope.content_loader_is_visible = true;
        AdvancedTools.runtest()
            .success(function(data) {
                $scope.integrity_result = data;
            }).finally(function() {

            $scope.content_loader_is_visible = false;
        });
    };

}).controller("BackofficeAdvancedCronController", function($scope, $interval, Header, AdvancedConfiguration, AdvancedCron) {

    $scope.header = new Header();
    $scope.header.button.left.is_visible = false;
    $scope.header.loader_is_visible = false;
    $scope.content_loader_is_visible = true;

    AdvancedCron.loadData().success(function(data) {
        $scope.header.title = data.title;
        $scope.header.icon = data.icon;
    }).finally(function() {
        $scope.content_loader_is_visible = false;
    });

    $scope.content_loader_is_visible = true;

    AdvancedConfiguration.findAll().success(function(data) {
        $scope.configs = data;
    }).finally(function() {});

    AdvancedCron.findAll().success(function(data) {
        $scope.system_tasks = data.system_tasks;
        $scope.tasks = data.tasks;
        $scope.apk_queue = data.apk_queue;
        $scope.source_queue = data.source_queue;
    }).finally(function() {
        $scope.content_loader_is_visible = false;
    });

    $scope.save = function() {

        $scope.form_loader_is_visible = true;

        AdvancedConfiguration.save($scope.configs).success(function(data) {

            var message = Label.save.error;
            if(angular.isObject(data) && angular.isDefined(data.message)) {
                message = data.message;
                $scope.message.isError(false);
            } else {
                $scope.message.isError(true);
            }
            $scope.message.setText(message)
                .show()
            ;
        }).error(function(data) {
            var message = Label.save.error;
            if(angular.isObject(data) && angular.isDefined(data.message)) {
                message = data.message;
            }

            $scope.message.setText(message)
                .isError(true)
                .show()
            ;
        }).finally(function() {
            $scope.form_loader_is_visible = false;

            $timeout(function() {
                location.reload();
            }, 500);
        });
    };

});