@extends(backpack_view('blank'))
@section('content')
<div ng-app="Dashboard" ng-controller="Main" ng-cloak class="mb-5">
    <div class="row mb-2" ng-repeat="row in rows track by $index">
        <div ng-repeat="col in row track by $index" class="col-sm-2" id="widget_@{{$parent.$index}}_@{{$index}}">
            <div class="card">
                <div class="card-header">
                    {{__('Loading')}}...
                </div>
            </div>
        </div>
        <div class="col-sm-2" ng-if="editing">
            <button class="btn btn-sm btn-info btn-block dropdown-toggle" id="dropdownMenuButton" data-toggle="dropdown">
                <i class="la la-plus"></i> Widget
            </button>
            <div class="dropdown-menu" aria-labelledby="dropdownMenuButton">
                <a class="dropdown-item" href="javascript:;" ng-repeat="widget in availableWidgets" ng-click="addWidget($parent.$index, widget.path)">
                    @{{widget.name}}
                </a>
            </div>
        </div>
        <div class="col-sm-2" ng-if="$index > 0 && editing">
            <button type="button" class="btn btn-sm btn-warning btn-block" ng-click="removeWidgetRow($index)">
                <i class="la la-minus"></i> {{__('Row')}}
            </button>
        </div>
    </div>
    <hr>
    <div class="row">
        <div class="col-sm-12">
            <button type="button" class="btn btn-sm" ng-class="{'btn-default':!editing, 'btn-success':editing}" ng-click="toggleEditing()">
                <span ng-if="editing"><i class="la la-check"></i> {{__('Done')}}</span>
                <span ng-if="!editing"><i class="la la-pencil"></i> {{__('Edit')}}</span>
            </button>
            <button ng-if="!editing" type="button" class="btn btn-sm btn-default" ng-click="refreshWidgets()">
                <i class="la la-refresh"></i> {{__('Refresh')}}
            </button>
            <button ng-if="editing" type="button" class="btn btn-sm btn-info" ng-click="addWidgetRow()">
                <i class="la la-plus"></i> {{__('Row')}}
            </button>
        </div>
    </div>
</div>
@endsection
@push('after_scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/angular.js/1.8.2/angular.min.js" integrity="sha512-7oYXeK0OxTFxndh0erL8FsjGvrl2VMDor6fVqzlLGfwOQQqTbYsGPv4ZZ15QHfSk80doyaM0ZJdvkyDcVO7KFA==" crossorigin="anonymous"></script>
<style>
[ng\:cloak], [ng-cloak], [data-ng-cloak], [x-ng-cloak], .ng-cloak, .x-ng-cloak {
  display: none !important;
}
.remove-widget {
    position: absolute;
    top: 6px;
    right: 20px;
    z-index: 1;
}
</style>
<script>
const availableWidgets = @json($availableWidgets);
angular.module('Dashboard', [])
    .controller('Main', function($scope, $http){
        $scope.editing = false;
        $scope.availableWidgets = availableWidgets;
        $scope.refreshWidgets = function() {
            $scope.rows = [];
            $http.get('{{route('dashboard.widget')}}').then(function(res){
                $scope.rows = res.data;
                $scope.loadWidgets();
            });
        }

        $scope.addWidgetRow = function() {
            $http.get('{{route('dashboard.widget.add-row')}}').then(function(res){
                $scope.rows = res.data;
                $scope.loadWidgets();
            });
        }

        $scope.removeWidgetRow = function(index) {
            $http.get('{{route('dashboard.widget.remove-row')}}?index=' + index).then(function(res){
                $scope.rows = res.data;
                $scope.loadWidgets();
            });
        }

        $scope.addWidget = function(index, widget) {
            $http.get('{{route('dashboard.widget.add')}}?index=' + index + '&widget=' + widget).then(function(res){
                $scope.rows = res.data;
                $scope.loadWidgets();
            });
        }

        $scope.removeWidget = function(index, widget) {
            $http.get('{{route('dashboard.widget.remove')}}?index=' + index + '&widget=' + widget).then(function(res){
                $scope.rows = res.data;
                $scope.loadWidgets();
            });
        }

        $scope.loadWidgets = function() {
            $('.widget-child').remove();
            $scope.rows.forEach(function(row, rowIndex){
                row.forEach(async function (widgetPath, widgetIndex){
                    // todo: move this into a directive
                    await $http.get('/{{config('backpack.base.route_prefix')}}/widgets/' + widgetPath).then(function(res){
                        let style = '';
                        if(!$scope.editing) {
                            style = 'style="display:none"'
                        }
                        const button = $('<div class="remove-widget" ' + style + '><button class="btn btn-xs btn-text"><i class="la la-trash"></i></button></div>');
                        button.click(function(){
                            $scope.$apply(function(){
                                $scope.removeWidget(rowIndex, widgetIndex);
                            });
                        });
                        const content = $(res.data);
                        const classes = content.attr('class');
                        const styles = content.attr('style');
                        const id = content.attr('id');
                        content.append(button);
                        $('#widget_' + rowIndex + '_' + widgetIndex)
                            .attr('class', classes)
                            .attr('style', styles)
                            .attr('id', id)
                            .html(content.children());
                    }, function(){
                        // on error: set widget empty
                        $('#widget_' + rowIndex + '_' + widgetIndex)
                            .removeAttr('class')
                            .html('');
                    })
                });
            })
        }

        $scope.toggleEditing = function() {
            $scope.editing=!$scope.editing;
            if($scope.editing) {
                $('.remove-widget').show();
            } else {
                $('.remove-widget').hide();
            }
        }

        $scope.refreshWidgets();
    });
</script>
@endpush