# Written by deploy.sh: sends every request to the live colour (@COLOR@).
resolver 127.0.0.11 valid=5s ipv6=off;

server {
    listen 80 default_server;
    server_name _;
    client_max_body_size 12m;

    location / {
        set $upstream http://ozepms-web-@COLOR@:8080;
        proxy_pass $upstream;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $http_x_real_ip;
        proxy_set_header X-Forwarded-For $http_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $http_x_forwarded_proto;
        proxy_read_timeout 90s;
        proxy_connect_timeout 5s;
    }
}
