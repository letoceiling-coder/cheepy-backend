#!/bin/bash
set -e
CFG="/etc/nginx/sites-enabled/online-parser.siteaacess.store"
if grep -q "location /app" "$CFG"; then
  echo "Already present"
  exit 0
fi
cp "$CFG" "${CFG}.bak"
awk '
/listen \[::\]:443 ssl; # managed by Certbot/ && !done {
  print "    location /app {"
  print "        proxy_pass http://127.0.0.1:8080;"
  print "        proxy_http_version 1.1;"
  print "        proxy_set_header Host $http_host;"
  print "        proxy_set_header Upgrade $http_upgrade;"
  print "        proxy_set_header Connection \"upgrade\";"
  print "        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;"
  print "        proxy_set_header X-Forwarded-Proto $scheme;"
  print "    }"
  print ""
  done=1
}
{ print }
' "$CFG" > "${CFG}.new" && mv "${CFG}.new" "$CFG"
echo "Added location /app"
nginx -t && systemctl reload nginx
