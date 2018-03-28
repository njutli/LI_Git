<?php
require './init.php';
?>
<!DOCTYPE html>
<html>
<head>
	<title>七八点照相馆订单管理系统</title>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<!-- 新 Bootstrap 核心 CSS 文件 -->
	<link href="https://cdn.bootcss.com/bootstrap/3.3.7/css/bootstrap.min.css" rel="stylesheet">
	 
	<!-- jQuery文件。务必在bootstrap.min.js 之前引入 -->
	<script src="https://cdn.bootcss.com/jquery/2.1.1/jquery.min.js"></script>
	 
	<!-- 最新的 Bootstrap 核心 JavaScript 文件 -->
	<script src="https://cdn.bootcss.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>

	<!--datatimepicker支持文件-->
	<script type="text/javascript" src="bootstrap-datetimepicker.js" charset="UTF-8"></script>
	<link href="bootstrap-datetimepicker.min.css" rel="stylesheet" media="screen">
	<script type="text/javascript" src="bootstrap-datetimepicker.js" charset="UTF-8"></script>
	<script type="text/javascript" src="bootstrap-datetimepicker.zh-CN.js" charset="UTF-8"></script>

	<!--angularjs支持文件-->
	<script src="http://cdn.static.runoob.com/libs/angular.js/1.4.6/angular.min.js"></script>

	<style type="text/css">
		@media(min-width: 992px)
		{
			#titleword
			{
				font-family: Microsoft YaHei;
				font-size: 30px;
				position: absolute;
				top: 25px;
				color: white;
			}

			#logopic
			{
				float: left;
				width: 30%;
				height:30%;
			}

			#loginformation
			{
				color: white;
			}

			#logbtn
			{
				margin-top: 30px;
				position: relative;
				left: 50px;
				color: white;
			}
		}

		@media(max-width: 768px)
		{
			#titleword
			{
				font-family: Microsoft YaHei;
				font-size: 15px;
				position: absolute;
				top: 15px;
				color: white;
			}

			#logopic
			{
				float: left;
				width: 100%;
				height:100%;
			}

			#loginformation
			{
				margin-top: 40px;
				font-size: 10px;
				color: white;
			}

			#logbtn
			{
				margin-top: 40px;
				position: relative;
				left: 5px;
				color: white;
			}			
		}
	</style>
</head>
<body>
	<div class="container-fluid" ng-app="seven8photo" style="background:url(bg.jpg) no-repeat center fixed;background-size:contain;background-position: center 0;background-repeat: no-repeat;background-attachment: fixed;background-size: cover; -webkit-background-size: cover;">
		<!--页头-->
		<div class="container-fluid" style="background-color: #AFD7D6;" ng-controller="head">
			<div class="col-md-4 col-md-push-1 col-xs-3">
				<img id="logopic" src="logo.jpg">
			</div>
			<div class="col-md-4 col-xs-8" style="position: relative;">
				<p id="titleword">七八点照相馆考勤管理系统</p>
			</div>
			<div class="col-md-2 col-md-push-2 col-xs-6" id="loginformation"><p>当前登录：{{loginfo}}</p></div>
			<div class="btn-group btn-group-xs col-md-push-1" id="logbtn">
				<a href="logout.php" class="btn btn-info">退出</a>
			</div>
			
		</div>

		<br>

		<div class="container" ng-controller="ctr">
			<ol class="nav nav-pills">
				<li class="active"><a href="arrange.php">排班</a></li>
				<li><a href="kaoqin.php">考勤</a></li>
				<li><a href="datacheck.php">数据查询</a></li>
				<li><a href="../func.php">返回</a></li>
			</ol>
			<br>
			<div class="container">
			    <form action="" class="form-inline col-md-4 col-md-push-4 col-ls-4 col-ls-push-4"  role="form">
			        <fieldset>

						<div class="form-group">
			                <label>日期</label>
			                <div class="input-group date form_date col-ls-8" data-date-format="yyyy-mm-dd">
			                    <input class="form-control text-center" size="25" type="text" value="" readonly ng-model="NowDate" ng-change="datechange()">
			                    <span class="input-group-addon"><span class="glyphicon glyphicon-remove"></span></span>
								<span class="input-group-addon"><span class="glyphicon glyphicon-calendar"></span></span>
			                </div>
			            </div>
			            
			        </fieldset>
			    </form>
			</div>
			<br>
			<div class="col-md-6 col-md-push-5" style="font-size: 15px;">
				{{showyear}}年，第{{showweek}}周，第{{showdate}}天
				<br>
				本周：{{start}}到{{end}}
				<br>
			</div>
			<br><br><br>
			<table class="table table-bordered text-center table-striped" style="background-color: white;">
				<thead>
					<tr>
						<td></td>
						<td>星期一<br>{{start}}</td>
						<td>星期二<br>{{day2}}</td>
						<td>星期三<br>{{day3}}</td>
						<td>星期四<br>{{day4}}</td>
						<td>星期五<br>{{day5}}</td>
						<td>星期六<br>{{day6}}</td>
						<td>星期日<br>{{end}}</td>
					</tr>
				</thead>
				<tr>
					<td>前台</td>
					<td>
						<div ng-style="Style[0]">{{ArrObj[0][0]}}</div>
						<div ng-style="Style[1]">{{ArrObj[0][1]}}</div>						
					</td>
					<td>
						<div ng-style="Style[2]">{{ArrObj[0][2]}}</div>
						<div ng-style="Style[3]">{{ArrObj[0][3]}}</div>	
					</td>
					<td>
						<div ng-style="Style[4]">{{ArrObj[0][4]}}</div>
						<div ng-style="Style[5]">{{ArrObj[0][5]}}</div>	
					</td>
					<td>
						<div ng-style="Style[6]">{{ArrObj[0][6]}}</div>
						<div ng-style="Style[7]">{{ArrObj[0][7]}}</div>	
					</td>
					<td>
						<div ng-style="Style[8]">{{ArrObj[0][8]}}</div>
						<div ng-style="Style[9]">{{ArrObj[0][9]}}</div>	
					</td>
					<td>
						<div ng-style="Style[10]">{{ArrObj[0][10]}}</div>
						<div ng-style="Style[11]">{{ArrObj[0][11]}}</div>	
					</td>
					<td>
						<div ng-style="Style[12]">{{ArrObj[0][12]}}</div>
						<div ng-style="Style[13]">{{ArrObj[0][13]}}</div>	
					</td>
				</tr>
				<tr>
					<td>化妆</td>
					<td>
						<div ng-style="Style[14]">{{ArrObj[1][0]}}</div>
						<div ng-style="Style[15]">{{ArrObj[1][1]}}</div>	
					</td>
					<td>
						<div ng-style="Style[16]">{{ArrObj[1][2]}}</div>
						<div ng-style="Style[17]">{{ArrObj[1][3]}}</div>	
					</td>
					<td>
						<div ng-style="Style[18]">{{ArrObj[1][4]}}</div>
						<div ng-style="Style[19]">{{ArrObj[1][5]}}</div>	
					</td>
					<td>
						<div ng-style="Style[20]">{{ArrObj[1][6]}}</div>
						<div ng-style="Style[21]">{{ArrObj[1][7]}}</div>	
					</td>
					<td>
						<div ng-style="Style[22]">{{ArrObj[1][8]}}</div>
						<div ng-style="Style[23]">{{ArrObj[1][9]}}</div>	
					</td>
					<td>
						<div ng-style="Style[24]">{{ArrObj[1][10]}}</div>
						<div ng-style="Style[25]">{{ArrObj[1][11]}}</div>	
					</td>
					<td>
						<div ng-style="Style[26]">{{ArrObj[1][12]}}</div>
						<div ng-style="Style[27]">{{ArrObj[1][13]}}</div>	
					</td>															
				</tr>
				<tr>
					<td>摄影</td>
					<td>
						<div ng-style="Style[28]">{{ArrObj[2][0]}}</div>
						<div ng-style="Style[29]">{{ArrObj[2][1]}}</div>
					</td>
					<td>
						<div ng-style="Style[30]">{{ArrObj[2][2]}}</div>
						<div ng-style="Style[31]">{{ArrObj[2][3]}}</div>
					</td>
					<td>
						<div ng-style="Style[32]">{{ArrObj[2][4]}}</div>
						<div ng-style="Style[33]">{{ArrObj[2][5]}}</div>
					</td>
					<td>
						<div ng-style="Style[34]">{{ArrObj[2][6]}}</div>
						<div ng-style="Style[35]">{{ArrObj[2][7]}}</div>
					</td>
					<td>
						<div ng-style="Style[36]">{{ArrObj[2][8]}}</div>
						<div ng-style="Style[37]">{{ArrObj[2][9]}}</div>
					</td>
					<td>
						<div ng-style="Style[38]">{{ArrObj[2][10]}}</div>
						<div ng-style="Style[39]">{{ArrObj[2][11]}}</div>
					</td>
					<td>
						<div ng-style="Style[40]">{{ArrObj[2][12]}}</div>
						<div ng-style="Style[41]">{{ArrObj[2][13]}}</div>
					</td>					
				</tr>
				<tr>
					<td>后期</td>
					<td>
						<div ng-style="Style[42]">{{ArrObj[3][0]}}</div>
						<div ng-style="Style[43]">{{ArrObj[3][1]}}</div>
					</td>
					<td>
						<div ng-style="Style[44]">{{ArrObj[3][2]}}</div>
						<div ng-style="Style[45]">{{ArrObj[3][3]}}</div>
					</td>
					<td>
						<div ng-style="Style[46]">{{ArrObj[3][4]}}</div>
						<div ng-style="Style[47]">{{ArrObj[3][5]}}</div>
					</td>
					<td>
						<div ng-style="Style[48]">{{ArrObj[3][6]}}</div>
						<div ng-style="Style[49]">{{ArrObj[3][7]}}</div>
					</td>
					<td>
						<div ng-style="Style[50]">{{ArrObj[3][8]}}</div>
						<div ng-style="Style[51]">{{ArrObj[3][9]}}</div>
					</td>
					<td>
						<div ng-style="Style[52]">{{ArrObj[3][10]}}</div>
						<div ng-style="Style[53]">{{ArrObj[3][11]}}</div>
					</td>
					<td>
						<div ng-style="Style[54]">{{ArrObj[3][12]}}</div>
						<div ng-style="Style[55]">{{ArrObj[3][13]}}</div>
					</td>										
				</tr>											
			</table>
			{{information}}
			<br><br>		
			<div>
				<button class="btn btn-warning" data-toggle="modal" data-target="#myModal" ng-click="changeappear()">
					修改
				</button>
				&nbsp;&nbsp;&nbsp;
				<button type="button" class="btn btn-success" ng-click="changesub()">
					确定
				</button>				
				<!-- 模态框（Modal） -->
				<div class="modal fade" id="myModal" tabindex="-1" role="dialog" aria-labelledby="myModalLabel" aria-hidden="true">
					<div class="modal-dialog">
						<div class="modal-content">
							<div class="modal-header">
								<button type="button" class="close" data-dismiss="modal" aria-hidden="true">
									&times;
								</button>
								<h4 class="modal-title" id="myModalLabel">
									排班表修改
								</h4>
							</div>
							<div class="modal-body">
								<div class="container form-inline">
									<div class="form-group">
										<label>日期选择&nbsp;&nbsp;&nbsp;</label>
										<select ng-model="modidate" ng-click="dateclick()">
											<option>1</option>
											<option>2</option>
											<option>3</option>
											<option>4</option>
											<option>5</option>
											<option>6</option>
											<option>7</option>
										</select>
									</div>
									<br>
									<div class="form-group" ng-click="posichoose()">
										<label>职务选择&nbsp;&nbsp;&nbsp;</label>
										<div class="radio">
											<label>
												<input type="radio" ng-model="radioposi" name="posiradio" value="radiorec">
												前台&nbsp;&nbsp;&nbsp;
											</label>
										</div>
										<div class="radio">
											<label>
												<input type="radio" ng-model="radioposi" name="posiradio" value="radiodre">
												化妆&nbsp;&nbsp;&nbsp;
											</label>
										</div>
										<div class="radio">
											<label>
												<input type="radio" ng-model="radioposi" name="posiradio" value="radiocam">
												摄影&nbsp;&nbsp;&nbsp;
											</label>
										</div>
										<div class="radio">
											<label>
												<input type="radio" ng-model="radioposi" name="posiradio" value="radiopro">
												后期&nbsp;&nbsp;&nbsp;
											</label>
										</div>										
									</div>
									<br>
									<div class="form-group">
										<label>姓名选择&nbsp;&nbsp;&nbsp;</label>
										<select ng-model="changename" ng-click="nameclick()">
											<option ng-repeat="x in people">{{x.name}}</option>
										</select>
									</div>
									<br>
									<div class="form-group">
										<label>标注选择&nbsp;&nbsp;&nbsp;</label>
										<div class="radio" id="radioone">
											<label style="color: orange">
												<input type="radio" ng-model="radioclass" name="classradio" value="radioday">
												█&nbsp;&nbsp;&nbsp;
											</label>
										</div>
										<div class="radio" id="radiotwo">
											<label style="color: green">
												<input type="radio" ng-model="radioclass" name="classradio" value="radionight">
												█&nbsp;&nbsp;&nbsp;
											</label>
										</div>
									</div>
									<br>

								</div>

							</div>
							<div class="modal-footer">
								<button type="button" class="btn btn-info" ng-click="add()">
									添加
								</button>
								<button type="button" class="btn btn-danger" ng-click="del()">
									删除
								</button>
							</div>
						</div><!-- /.modal-content -->
					</div><!-- /.modal -->
				</div>
			</div>

		</div>
	</div>
<br><br><br>

<script type="text/javascript">

	var app = angular.module('seven8photo', []);
	app.config(function($httpProvider)
	{
		$httpProvider.defaults.transformRequest=function(obj)
		{
			var str=[];
			for(var p in obj)
			{
				str.push(encodeURIComponent(p)+"="+encodeURIComponent(obj[p]));
			}
			return str.join("&");
		};
		$httpProvider.defaults.headers.post=
		{'Content-Type':'application/x-www-form-urlencoded'}
	});
	app.controller('head',function($scope)
	{
		$scope.loginfo="<?php echo $_SESSION['user']['name']; ?>";
	});
	app.controller('ctr', function($scope,$http)
	{
		//排班表各单元格式
		$scope.Style=[];
		for(var i=0;i<56;i++)
		{
			$scope.Style.push(
			{
				"color":"black",
				"display":"inline"
			});
		};
		typereset();
		//初始化排班表
		$scope.ArrObj=[
			["","","","","","","","","","","","","",""],
			["","","","","","","","","","","","","",""],
			["","","","","","","","","","","","","",""],
			["","","","","","","","","","","","","",""],
		];

		//用于提交的数据
		$scope.ArrDataSub=[
			["","","","","","",""],
			["","","","","","",""],
			["","","","","","",""],
			["","","","","","",""],
		];

		$scope.changeappear=function()
		{
			$scope.radioclass="";
		}

		function eachdayadd(pos,day)
		{
			if ($scope.ArrObj[pos][2*day]=="")
			{
				$scope.ArrObj[pos][2*day]=$scope.changename;
				if ($scope.radioclass=="radioday")
				{
					$scope.Style[14*pos+2*day]={
						"color":"orange",
						"display":"inline"
					};
				}
				else if($scope.radioclass=="radionight")
				{
					$scope.Style[14*pos+2*day]={
						"color":"green",
						"display":"inline"
					};								
				}
				else
				{}	
			}
			else
			{
				if ($scope.ArrObj[pos][2*day+1]!="")
				{
					alert("人数达到上限，无法继续添加");
					return;
				}
				if (($scope.ArrObj[pos][2*day]+$scope.ArrObj[pos][2*day+1]).search($scope.changename)!=-1)
				{
					alert("请勿重复添加");
					return;
				}
				$scope.ArrObj[pos][2*day+1]=$scope.changename;

				if ($scope.radioclass=="radioday")
				{
					$scope.Style[14*pos+1+2*day]={
						"color":"orange",
						"display":"inline"
					};
				}
				else if($scope.radioclass=="radionight")
				{
					$scope.Style[14*pos+1+2*day]={
						"color":"green",
						"display":"inline"
					};								
				}
				else
				{
					$scope.Style[14*pos+1+2*day]={
						"color":"black",
						"display":"inline"
					};										
				}
			}	
		}

		//排班表添加内容
		$scope.add=function()
		{
			if (typeof($scope.modidate)=="undefined")
			{
				alert("未选择日期");
				return;
			}
			else if (typeof($scope.changename)=="undefined")
			{
				alert("未选择姓名");
				return;
			}

			switch($scope.modidate)
			{
				case "1":
					switch($scope.radioposi)
					{
						case "radiorec":
							eachdayadd(0,0);
							break;
						case "radiodre":
							eachdayadd(1,0);					
							break;
						case "radiocam":
							eachdayadd(2,0);						
							break;
						case "radiopro":
							eachdayadd(3,0);	
							break;
						default:
							break;
					}
					$scope.information="已更新，未提交";
					break;
				case "2":
					switch($scope.radioposi)
					{
						case "radiorec":
							eachdayadd(0,1);
							break;
						case "radiodre":
							eachdayadd(1,1);
							break;
						case "radiocam":
							eachdayadd(2,1);
							break;
						case "radiopro":
							eachdayadd(3,1);			
							break;
						default:
							break;
					}
					$scope.information="已更新，未提交";
					break;
				case "3":
					switch($scope.radioposi)
					{
						case "radiorec":
							eachdayadd(0,2);
							break;
						case "radiodre":
							eachdayadd(1,2);				
							break;
						case "radiocam":
							eachdayadd(2,2);					
							break;
						case "radiopro":
							eachdayadd(3,2);						
							break;
						default:
							break;
					}
					$scope.information="已更新，未提交";
					break;
				case "4":
					switch($scope.radioposi)
					{
						case "radiorec":
							eachdayadd(0,3);
							break;
						case "radiodre":
							eachdayadd(1,3);
							break;
						case "radiocam":
							eachdayadd(2,3);
							break;
						case "radiopro":
							eachdayadd(3,3);	
							break;
						default:
							break;
					}
					$scope.information="已更新，未提交";
					break;
				case "5":
					switch($scope.radioposi)
					{
						case "radiorec":
							eachdayadd(0,4);
							break;
						case "radiodre":
							eachdayadd(1,4);
							break;
						case "radiocam":
							eachdayadd(2,4);
							break;
						case "radiopro":
							eachdayadd(3,4);
							break;
						default:
							break;
					}
					$scope.information="已更新，未提交";
					break;
				case "6":
					switch($scope.radioposi)
					{
						case "radiorec":
							eachdayadd(0,5);
							break;
						case "radiodre":
							eachdayadd(1,5);
							break;
						case "radiocam":
							eachdayadd(2,5);
							break;
						case "radiopro":
							eachdayadd(3,5);
							break;
						default:
							break;
					}
					$scope.information="已更新，未提交";
					break;
				case "7":
					switch($scope.radioposi)
					{
						case "radiorec":
							eachdayadd(0,6);
							break;
						case "radiodre":
							eachdayadd(1,6);
							break;
						case "radiocam":
							eachdayadd(2,6);	
							break;
						case "radiopro":
							eachdayadd(3,6);		
							break;
						default:
							break;
					}
					$scope.information="已更新，未提交";
					break;				
				default:
					break;
			}
		}

		function eachdaydel(pos,day)
		{
			if ($scope.ArrObj[pos][2*day]=="")
			{
				alert("无可删除人员");
				return;
			}

			//若想要删除的人员不在名单中，则报错退出，否则删除
			if (($scope.ArrObj[pos][2*day]+$scope.ArrObj[pos][2*day+1]).search($scope.changename)==-1)
			{
				alert("无法删除，欲删除人员不在名单中");
				return;
			}
		
			//若想要删除的人员为第一个，则将第二个前移，并清空第二个
			if ($scope.ArrObj[pos][2*day].search($scope.changename)!=-1)
			{
				$scope.ArrObj[pos][2*day]=$scope.ArrObj[pos][2*day+1];
				$scope.Style[14*pos+2*day].color=$scope.Style[14*pos+1+2*day].color;
			}
			else//若想要删除的人员为第二个，则直接删除
			{}
			$scope.ArrObj[pos][2*day+1]="";
			$scope.Style[14*pos+1+2*day].color="black";
		}

		$scope.del=function()
		{
			if (typeof($scope.modidate)=="undefined")
			{
				alert("未选择日期");
				return;
			}
			else if (typeof($scope.changename)=="undefined")
			{
				alert("未选择姓名");
				return;
			}

			switch($scope.modidate)
			{
				case "1":
					switch($scope.radioposi)
					{
						case "radiorec":
							eachdaydel(0,0);
							break;
						case "radiodre":
							eachdaydel(1,0);
							break;
						case "radiocam":					
							eachdaydel(2,0);
							break;
						case "radiopro":
							eachdaydel(3,0);
							break;
						default:
							break;
					}
					$scope.information="已更新，未提交";
					break;	
				case "2":
					switch($scope.radioposi)
					{
						case "radiorec":
							eachdaydel(0,1);
							break;
						case "radiodre":
							eachdaydel(1,1);
							break;
						case "radiocam":
							eachdaydel(2,1);
							break;
						case "radiopro":
							eachdaydel(3,1);
							break;
						default:
							break;
					}
					$scope.information="已更新，未提交";
					break;
				case "3":
					switch($scope.radioposi)
					{
						case "radiorec":
							eachdaydel(0,2);
							break;
						case "radiodre":
							eachdaydel(1,2);
							break;
						case "radiocam":
							eachdaydel(2,2);
							break;
						case "radiopro":
							eachdaydel(3,2);
							break;
						default:
							break;
					}
					$scope.information="已更新，未提交";
					break;
				case "4":
					switch($scope.radioposi)
					{
						case "radiorec":
							eachdaydel(0,3);
							break;
						case "radiodre":
							eachdaydel(1,3);
							break;
						case "radiocam":
							eachdaydel(2,3);
							break;
						case "radiopro":
							eachdaydel(3,3);
							break;
						default:
							break;
					}
					$scope.information="已更新，未提交";
					break;
				case "5":
					switch($scope.radioposi)
					{
						case "radiorec":
							eachdaydel(0,4);
							break;
						case "radiodre":
							eachdaydel(1,4);
							break;
						case "radiocam":
							eachdaydel(2,4);
							break;
						case "radiopro":
							eachdaydel(3,4);
							break;
						default:
							break;
					}
					$scope.information="已更新，未提交";
					break;
				case "6":
					switch($scope.radioposi)
					{
						case "radiorec":
							eachdaydel(0,5);
							break;
						case "radiodre":
							eachdaydel(1,5);
							break;
						case "radiocam":
							eachdaydel(2,5);
							break;
						case "radiopro":
							eachdaydel(3,5);
							break;
						default:
							break;
					}
					$scope.information="已更新，未提交";
					break;															
				case "7":
					switch($scope.radioposi)
					{
						case "radiorec":
							eachdaydel(0,6);
							break;
						case "radiodre":
							eachdaydel(1,6);
							break;
						case "radiocam":
							eachdaydel(2,6);
							break;
						case "radiopro":
							eachdaydel(3,6);
							break;
						default:
							break;
					}
					$scope.information="已更新，未提交";
					break;
				default:
					break;
			}
		}

		//当选择的职位发生变化时相应的人员选择也发生变化
		$scope.posichoose=function()
		{
			switch($scope.radioposi)
			{
				case "radiorec":
					$scope.people=$scope.recept;
					break;
				case "radiodre":
					$scope.people=$scope.dress;
					break;
				case "radiocam":
					$scope.people=$scope.camera;
					break;
				case "radiopro":
					$scope.people=$scope.process;
					break;
				default:
					break;
			}			
		}

		//提取人员数据供选择
		function getdata(switchpara)
		{
			$http(
			{
			    method: "POST",
			    data:{para:switchpara},
			    url:"sqlinput.php"
			})
			.success(function (response)
			{
				res = response.records;
				//$scope.count=res.length;
				if (switchpara==1)
				{
					$scope.recept=res;
				}
				else if (switchpara==2)
				{
					$scope.dress=res;
				}
				else if (switchpara==3)
				{
					$scope.camera=res;
				}
				else if (switchpara==4)
				{
					$scope.process=res;
				}
				else
				//$scope.people=res;
				console.log(response);
			});				
		}

		getdata(1);
		getdata(2);
		getdata(3);
		getdata(4);

		//初始化排班表
		function getdata2()
		{
			$http(
			{
			    method: "POST",
			    data:{postyear:$scope.showyear,postweek:$scope.showweek},
			    url:"sqlinput3.php"
			})
			.success(function (response)
			{
				res = response.records;
				//$scope.count=res.length;
				var EmpArr;
				var TempData;
				if (res.length!=0)
				{
					for(var i=0;i<7;i++)
					{
						TempData=getdatatran(2*i,(2*i+1),res[i]['rec']);
						$scope.ArrObj[0][2*i]=TempData.split(",")[0];
						$scope.ArrObj[0][2*i+1]=TempData.split(",")[1];
					}
					for(var i=0;i<7;i++)
					{
						TempData=getdatatran(2*i+14,(2*i+15),res[i]['dre']);
						$scope.ArrObj[1][2*i]=TempData.split(",")[0];
						$scope.ArrObj[1][2*i+1]=TempData.split(",")[1];
					}
					for(var i=0;i<7;i++)
					{
						TempData=getdatatran(2*i+28,(2*i+29),res[i]['car']);
						$scope.ArrObj[2][2*i]=TempData.split(",")[0];
						$scope.ArrObj[2][2*i+1]=TempData.split(",")[1];
					}
					for(var i=0;i<7;i++)
					{
						TempData=getdatatran(2*i+42,(2*i+43),res[i]['pro']);
						$scope.ArrObj[3][2*i]=TempData.split(",")[0];
						$scope.ArrObj[3][2*i+1]=TempData.split(",")[1];
					}
				}
				else
				{
					typereset();
					objreset();		
				}
			});			
		}

		function getdatatran(style1,style2,paradata)
		{
			var TempArr=paradata.split(",");
			var result1;
			var result2;
			if (TempArr.length==2)
			{
				if (TempArr[0].indexOf("+")!=-1)
				{
					$scope.Style[style1]={
						"color":"orange",
						"display":"inline"
					};
					result1=TempArr[0].substring(0,2);
				}
				else if (TempArr[0].indexOf("-")!=-1)
				{
					$scope.Style[style1]={
						"color":"green",
						"display":"inline"
					};
					result1=TempArr[0].substring(0,2);
				}
				else
				{
					$scope.Style[style1]={
						"color":"black",
						"display":"inline"
					};
					result1=TempArr[0];
				}

				if (TempArr[1].indexOf("+")!=-1)
				{
					$scope.Style[style2]={
						"color":"orange",
						"display":"inline"
					};
					result2=TempArr[1].substring(0,2);
				}
				else if (TempArr[1].indexOf("-")!=-1)
				{
					$scope.Style[style2]={
						"color":"green",
						"display":"inline"
					};
					result2=TempArr[1].substring(0,2);
				}
				else
				{
					$scope.Style[style2]={
						"color":"black",
						"display":"inline"
					};
					result2=TempArr[1];
				}
			}
			else
			{
				if (paradata.indexOf("+")!=-1)
				{
					result1=paradata.substring(0,2);
					$scope.Style[style1]={
							"color":"orange",
							"display":"inline"
						};							
				}
				else if (paradata.indexOf("-")!=-1)
				{
					result1=paradata.substring(0,2);
					$scope.Style[style1]={
							"color":"green",
							"display":"inline"
						};							
				}
				else
				{
					result1=paradata;
					$scope.Style[style1]={
							"color":"black",
							"display":"inline"
						};							
				}

				result2="";
			}
			return result1+","+result2;
		}

		function typereset()
		{
			for(var i=0;i<$scope.Style.length;i++)
			{
				$scope.Style[i].color="balck";
			}
		}

		function objreset()
		{
			for(var i=0;i<4;i++)
			{
				for(var j=0;j<14;j++)
				{
					$scope.ArrObj[i][j]="";
				}				
			}
		}

		//提交数据前组织数据
		function orgdata(pos,day)
		{
			if ($scope.Style[14*pos+2*day].color=="orange")
			{
				$scope.ArrDataSub[pos][day]=$scope.ArrObj[pos][2*day]+"+";
			}
			else if ($scope.Style[14*pos+2*day].color=="green")
			{
				$scope.ArrDataSub[pos][day]=$scope.ArrObj[pos][2*day]+"-";
			}
			else
			{
				$scope.ArrDataSub[pos][day]=$scope.ArrObj[pos][2*day];
			}

			if ($scope.Style[14*pos+1+2*day].color=="orange")
			{
				$scope.ArrDataSub[pos][day]=$scope.ArrDataSub[pos][day]+","+$scope.ArrObj[pos][2*day+1]+"+";
			}
			else if ($scope.Style[14*pos+1+2*day].color=="green")
			{
				$scope.ArrDataSub[pos][day]=$scope.ArrDataSub[pos][day]+","+$scope.ArrObj[pos][2*day+1]+"-";
			}
			else
			{
				if ($scope.ArrObj[pos][2*day+1]!="")
				{
					$scope.ArrDataSub[pos][day]=$scope.ArrDataSub[pos][day]+","+$scope.ArrObj[pos][2*day+1];	
				}
			}
		}
		//“确定”按钮对应的出发事件，将数据提交至数据库
		function subdata()
		{
			for(var i=0;i<7;i++)
			{
				orgdata(0,i);
				orgdata(1,i);
				orgdata(2,i);
				orgdata(3,i);
				$http(
				{
				    method: "POST",
				    data:{postyear:$scope.showyear,postweek:$scope.showweek,postday:(i+1),postrec:$scope.ArrDataSub[0][i],postdre:$scope.ArrDataSub[1][i],postcam:$scope.ArrDataSub[2][i],postpro:$scope.ArrDataSub[3][i]},
				    url:"sqlinput2.php"
				})
				.success(function (response)
				{
					res = response.records;
					//console.log(response);
				});			
			}
		}

		$scope.changesub=function()
		{
			if (typeof($scope.NowDate)=="undefined")
			{
				alert("未选中日期!");
				return;
			}
			
			if ((typeof($scope.ArrObj[0][0])=="undefined")|(typeof($scope.ArrObj[0][2])=="undefined")|(typeof($scope.ArrObj[0][4])=="undefined")|(typeof($scope.ArrObj[0][6])=="undefined")|(typeof($scope.ArrObj[0][8])=="undefined")|(typeof($scope.ArrObj[0][10])=="undefined")|(typeof($scope.ArrObj[0][12])=="undefined")|(typeof($scope.ArrObj[1][0])=="undefined")|(typeof($scope.ArrObj[1][2])=="undefined")|(typeof($scope.ArrObj[1][4])=="undefined")|(typeof($scope.ArrObj[1][6])=="undefined")|(typeof($scope.ArrObj[1][8])=="undefined")|(typeof($scope.ArrObj[1][10])=="undefined")|(typeof($scope.ArrObj[1][12])=="undefined")|(typeof($scope.ArrObj[2][0])=="undefined")|(typeof($scope.ArrObj[2][2])=="undefined")|(typeof($scope.ArrObj[2][4])=="undefined")|(typeof($scope.ArrObj[2][6])=="undefined")|(typeof($scope.ArrObj[2][8])=="undefined")|(typeof($scope.ArrObj[2][10])=="undefined")|(typeof($scope.ArrObj[2][12])=="undefined")|(typeof($scope.ArrObj[3][0])=="undefined")|(typeof($scope.ArrObj[3][2])=="undefined")|(typeof($scope.ArrObj[3][4])=="undefined")|(typeof($scope.ArrObj[3][6])=="undefined")|(typeof($scope.ArrObj[3][8])=="undefined")|(typeof($scope.ArrObj[3][10])=="undefined")|(typeof($scope.ArrObj[3][12])=="undefined")|($scope.ArrObj[0][0]=="")|($scope.ArrObj[0][2]=="")|($scope.ArrObj[0][4]=="")|($scope.ArrObj[0][6]=="")|($scope.ArrObj[0][8]=="")|($scope.ArrObj[0][10]=="")|($scope.ArrObj[0][12]=="")|($scope.ArrObj[1][0]=="")|($scope.ArrObj[1][2]=="")|($scope.ArrObj[1][4]=="")|($scope.ArrObj[1][6]=="")|($scope.ArrObj[1][8]=="")|($scope.ArrObj[1][10]=="")|($scope.ArrObj[1][12]=="")|($scope.ArrObj[2][0]=="")|($scope.ArrObj[2][2]=="")|($scope.ArrObj[2][4]=="")|($scope.ArrObj[2][6]=="")|($scope.ArrObj[2][8]=="")|($scope.ArrObj[2][10]=="")|($scope.ArrObj[2][12]=="")|($scope.ArrObj[3][0]=="")|($scope.ArrObj[3][2]=="")|($scope.ArrObj[3][4]=="")|($scope.ArrObj[3][6]=="")|($scope.ArrObj[3][8]=="")|($scope.ArrObj[3][10]=="")|($scope.ArrObj[3][12]==""))
			{
				alert("未全部选择！");
				return;
			}
			subdata();

			$scope.information="已提交";

			alert("ok");
		}

		$scope.datechange=function()
		{
			 var flag=1;
			/*
			如果1月1号是星期天，则算作上一年的最后一周，否则算作这一年的第一周
			*/

			//根据输入获取年月日
			var sledate=$scope.NowDate;
			var datearr=sledate.split('-');
			var choyear=datearr[0];
			var chomonth=(parseInt(datearr[1])-1).toString();
			var chodate=datearr[2];//(parseInt(datearr[2])+1).toString();

			//构造选中日期的对象
			var nowdateobj=new Date(choyear,chomonth,chodate);
			var nowdatetime=nowdateobj.getTime();

			//当选中日期大于12.26时，若下一年1.1为星期天，则继续执行，否则判断选中日期与下一年1.1是否在同一周
			//若不在同一周，则继续执行，否则周次为下一年第一周
			if (nowdateobj.getMonth()>10)
			{
				if (nowdateobj.getDate()>26)
				{
					//下一年1月1日
					var nextyear=new Date((parseInt(choyear)+1),"0","1");
					//选中日期与下一年1月1日相差的天数
					var temp=(nextyear.getTime()-nowdateobj.getTime())/(24*3600000);
					if (nextyear.getDay()>temp)
					{
						$scope.showyear=parseInt(choyear)+1;
						$scope.showweek=1;
						flag=0;
					}
					else
					{

					}
				}
			}

			//构造本年1月1日的日期对象
			var oridateobj=new Date(choyear,"0","1");
			//oridateobj.setTime(oridateobj.getTime()+24*3600000);
			var oridatetime=oridateobj.getTime();

			//获取本年1月1日所在周的第一天
			//1月1日与其所在周的第一天相差的天数
			var daygap=parseInt(oridateobj.getDay());
			//1月1日与其所在周的第一天相差的毫秒数
			var timegap=daygap*24*3600000;

			//1月1日所在周的第一天的日期
			var startdateobj=new Date();
			var startdatetime=oridatetime-timegap;
			startdateobj.setTime(startdatetime);

			//第x天的周数使用正常算法计算第(x-1)天的周数得到
			//如需2017.09.03的周数，则计算2017.09.02的周数，由于每周为周一至周日，所以2017.09.03本应为第36周的第一天，这里根据2017.09.02计算为第35周的最后一天
			nowdateobj.setTime(nowdatetime-24*3600000);

			//选中日期与第一周的第一天相差的毫秒数
			var timegap2=nowdateobj.getTime()-startdateobj.getTime();

			if (timegap2<0)
			{
				choyear=parseInt(choyear)-1;
				//构造选中日期的对象
				nowdateobj=new Date(choyear,chomonth,chodate);
				nowdatetime=nowdateobj.getTime();

				//构造本年1月1日的日期对象
				oridateobj=new Date(choyear,"0","1");
				oridatetime=oridateobj.getTime();


				//获取本年1月1日所在周的第一天
				//1月1日与其所在周的第一天相差的天数
				daygap=parseInt(oridateobj.getDay());
				//1月1日与其所在周的第一天相差的毫秒数
				imegap=daygap*24*3600000;

				//1月1日所在周的第一天的日期
				startdateobj=new Date();
				startdatetime=oridatetime-timegap;
				startdateobj.setTime(startdatetime);

				nowdateobj=new Date(choyear,"11","31");

				timegap2=nowdateobj.getTime()-startdateobj.getTime();
			}

			//选中日期与第一周相差的周数
			var weekgap=parseInt(timegap2/(7*24*3600000));

			if (flag==1)
			{
				$scope.showyear=choyear;
				$scope.showweek=weekgap+1;
			}

			//该周开始日期
			var startdate=new Date();
			startdate.setTime(startdateobj.getTime()+(weekgap*7+1)*24*3600000);
			//该周结束日期
			var enddate=new Date();
			enddate.setTime(startdate.getTime()+6*24*3600000);

			var day2=new Date();
			day2.setTime(startdateobj.getTime()+(weekgap*7+2)*24*3600000);
			var day3=new Date();
			day3.setTime(startdateobj.getTime()+(weekgap*7+3)*24*3600000);
			var day4=new Date();
			day4.setTime(startdateobj.getTime()+(weekgap*7+4)*24*3600000);
			var day5=new Date();
			day5.setTime(startdateobj.getTime()+(weekgap*7+5)*24*3600000);
			var day6=new Date();
			day6.setTime(startdateobj.getTime()+(weekgap*7+6)*24*3600000);

			$scope.start=startdate.getFullYear()+"-"+(parseInt(startdate.getMonth())+1)+"-"+startdate.getDate();
			$scope.end=enddate.getFullYear()+"-"+(parseInt(enddate.getMonth())+1)+"-"+enddate.getDate();
			
			$scope.day2=day2.getFullYear()+"-"+(parseInt(day2.getMonth())+1)+"-"+day2.getDate();
			$scope.day3=day3.getFullYear()+"-"+(parseInt(day3.getMonth())+1)+"-"+day3.getDate();
			$scope.day4=day4.getFullYear()+"-"+(parseInt(day4.getMonth())+1)+"-"+day4.getDate();
			$scope.day5=day5.getFullYear()+"-"+(parseInt(day5.getMonth())+1)+"-"+day5.getDate();
			$scope.day6=day6.getFullYear()+"-"+(parseInt(day6.getMonth())+1)+"-"+day6.getDate();

			//该周第一天与选中日期的差值
			var starttonow=(nowdateobj.getTime()-startdate.getTime())/(24*3600000);

			//由于前面计算第x天的周数使用正常算法计算第(x-1)天的周数得到，所以这里要多加1
			$scope.showdate=starttonow+1+1;
			getdata2();

		}
	})
</script>

<script type="text/javascript">
	$('.form_date').datetimepicker({
        language:  'zh-CN',
        weekStart: 1,
        todayBtn:  1,
		autoclose: 1,
		todayHighlight: 1,
		startView: 2,
		minView: 2,
		forceParse: 0
    });
</script>
</body>
</html>
