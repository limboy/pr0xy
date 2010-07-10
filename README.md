在线代理程序

环境要求
======
nginx
php 5.2+
sqlite 3

nginx配置
======
	location /get/
	{
		if ( $uri ~ ^/get/http./+([^/]+)/(.+)$) {
		  set $hostx $1;
		  set $addrs $2;
		}
		resolver 208.67.220.220;
		proxy_pass http://$hostx/$addrs;
		proxy_set_header referer http://$hostx;
	}

注意事项
======
* 新建cache文件夹，同时确保cache和db目录可写
* 如果服务端不使用https的话，打开pr0xy.php，将$server_use_https设为false
