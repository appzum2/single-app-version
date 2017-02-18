App.directive('sbBackgroundImage', function($sbhttp, Url, $rootScope) {
    return {
        restrict: 'A',
        controller: function($scope, $state, $stateParams, $location) {
            $scope.value_id = $state.current.name == "home" ? "home" : $stateParams.value_id;
        },
        link: function(scope, element) {

            if(angular.isDefined(scope.value_id)) {
                $sbhttp({
                    method: 'GET',
                    url: Url.get('front/mobile/backgroundimage', {value_id: scope.value_id}),
                    cache: $rootScope.isOffline,
                    timeout: 10000
                }).success(function(background_images) {

                    if(background_images) {

                        var src = background_images.tablet;
                        if (window.innerWidth > 350) {
                            src = background_images.hd;
                        } else if (background_images.standard) {
                            src = background_images.standard;
                        }

                        if (src) {
                            var img = new Image();
                            img.src = src;
                            img.onload = function () {
                                scope.setBackgroundImageStyle(src);
                            };
                            if (img.complete || $rootScope.isOffline) {
                                scope.setBackgroundImageStyle(src);
                            }
                            /*else if(Application.is_ios) {
                             scope.setBackgroundImageStyle(src);
                             }*/
                        }

                    }

                }).finally(function() {
                    setTimeout(function() {
                        if(typeof navigator.splashscreen != "undefined") {
                            navigator.splashscreen.hide();
                        }
                    }, 5000);
                });
            }

            scope.setBackgroundImageStyle = function(src) {
                angular.element(element).addClass("has-background-image").css({"background-image": "url('" + src + "')"});
                setTimeout(function(){
                    if(typeof navigator.splashscreen != "undefined") {
                        navigator.splashscreen.hide();
                    }
                },500);
            };

        }
    }
});