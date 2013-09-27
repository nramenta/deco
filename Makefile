.PHONY: all clean

all:
	box build -v

clean:
	rm ./deco.phar
