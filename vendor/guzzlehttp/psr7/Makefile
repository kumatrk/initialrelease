test:
	vendor/bin/phpunit $(TEST)

check-tag:
	$(if $(TAG),,$(error TAG is not defined. Pass via "make tag TAG=4.2.1"))

tag: check-tag
	@echo Tagging $(TAG)
	chag update $(TAG)
	git commit -a -m '$(TAG) release'
	chag tag
	@echo "Release has been created. Push using 'make release'"
	@echo "Changes made in the release commit"
	git diff HEAD~1 HEAD

release: check-tag
	git push origin master
	git push origin $(TAG)
